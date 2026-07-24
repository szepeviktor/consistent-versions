<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

use Composer\Semver\VersionParser;
use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;
use UnexpectedValueException;

final class VersionNormalizer implements Normalizer
{
    public function __construct(private readonly VersionParser $parser = new VersionParser())
    {
    }

    public function normalize(string|int|float|bool|null $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new ConfigurationException('The "version" normalizer expects a string or number');
        }

        try {
            return $this->clean($this->parser->normalize((string) $value));
        } catch (UnexpectedValueException $exception) {
            throw new ConfigurationException(
                sprintf('Invalid version "%s": %s', (string) $value, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    private function clean(string $version): string
    {
        $version = str_ends_with($version, '-dev') ? substr($version, 0, -4) : $version;
        $parts = explode('.', $version);
        while (count($parts) > 3 && end($parts) === '0') {
            array_pop($parts);
        }

        return implode('.', $parts);
    }
}
