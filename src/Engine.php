<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;
use SzepeViktor\ConsistentVersions\Normalizer\NormalizerRegistry;
use SzepeViktor\ConsistentVersions\Reader\ReaderRegistry;

final class Engine
{
    private readonly ReferenceResolver $resolver;

    public function __construct(
        ?ReaderRegistry $readers = null,
        ?NormalizerRegistry $normalizers = null,
        ?Selector $selector = null
    ) {
        $this->resolver = new ReferenceResolver(
            $readers ?? ReaderRegistry::withDefaults(),
            $normalizers ?? NormalizerRegistry::withDefaults(),
            $selector ?? new Selector()
        );
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    public function run(array $configuration, string $baseDirectory): Report
    {
        $checks = $configuration['checks'] ?? null;
        if (!is_array($checks) || $checks === []) {
            throw new ConfigurationException('Configuration must contain a non-empty "checks" map');
        }

        $results = [];
        foreach ($checks as $name => $check) {
            if (!is_string($name) || !is_array($check)) {
                throw new ConfigurationException('Every check must have a name and an object definition');
            }
            $results[] = $this->runCheck($name, $check, $baseDirectory);
        }

        return new Report($results);
    }

    /**
     * @param array<array-key, mixed> $check
     */
    private function runCheck(string $name, array $check, string $baseDirectory): CheckResult
    {
        if (!array_key_exists('expected', $check)) {
            throw new ConfigurationException(sprintf('Check "%s" has no "expected" source or literal', $name));
        }
        $values = $check['values'] ?? null;
        if (!is_array($values) || $values === []) {
            throw new ConfigurationException(sprintf('Check "%s" must contain a non-empty "values" map', $name));
        }

        $normalizers = $check['normalize'] ?? [];
        if (is_string($normalizers)) {
            $normalizers = [$normalizers];
        }
        if (!is_array($normalizers)) {
            throw new ConfigurationException(sprintf('Check "%s" normalize must be a string or list', $name));
        }
        $normalizerNames = [];
        foreach ($normalizers as $normalizer) {
            if (!is_string($normalizer)) {
                throw new ConfigurationException(sprintf('Check "%s" normalizers must be strings', $name));
            }
            $normalizerNames[] = $normalizer;
        }

        $expected = $this->resolver->resolve($check['expected'], $baseDirectory, $normalizerNames);
        $mismatches = [];
        foreach ($values as $label => $reference) {
            if (!is_string($label)) {
                throw new ConfigurationException(sprintf('Check "%s" value labels must be strings', $name));
            }
            $actual = $this->resolver->resolve($reference, $baseDirectory, $normalizerNames);
            if ($actual !== $expected) {
                $mismatches[$label] = $actual;
            }
        }

        return new CheckResult($name, $expected, $mismatches);
    }
}
