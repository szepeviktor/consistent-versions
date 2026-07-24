<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;
use UnexpectedValueException;

final class ComposerMinimumNormalizer implements Normalizer
{
    public function __construct(private readonly VersionParser $parser = new VersionParser())
    {
    }

    public function normalize(string|int|float|bool|null $value): string
    {
        if (!is_string($value)) {
            throw new ConfigurationException('The "composer-minimum" normalizer expects a constraint string');
        }

        try {
            $intervals = Intervals::get($this->parser->parseConstraints($value));
        } catch (UnexpectedValueException $exception) {
            throw new ConfigurationException(
                sprintf('Invalid Composer constraint "%s": %s', $value, $exception->getMessage()),
                0,
                $exception
            );
        }

        if ($intervals['numeric'] === []) {
            throw new ConfigurationException(sprintf('Composer constraint "%s" has no numeric versions', $value));
        }

        $start = $intervals['numeric'][0]->getStart();
        if ($start->getOperator() === '>') {
            throw new ConfigurationException(sprintf(
                'Composer constraint "%s" has an exclusive lower bound, so it has no concrete minimum',
                $value
            ));
        }

        $version = $start->getVersion();
        $version = str_ends_with($version, '-dev') ? substr($version, 0, -4) : $version;
        $parts = explode('.', $version);
        while (count($parts) > 2 && end($parts) === '0') {
            array_pop($parts);
        }

        return implode('.', $parts);
    }
}
