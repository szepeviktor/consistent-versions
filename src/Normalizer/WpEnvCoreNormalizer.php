<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;

final class WpEnvCoreNormalizer implements Normalizer
{
    public function normalize(string|int|float|bool|null $value): string
    {
        if (!is_string($value)) {
            throw new ConfigurationException('The "wp-env-core" normalizer expects a string');
        }

        if (preg_match('/^WordPress\/WordPress#(\d+\.\d+)-branch$/', $value, $matches) === 1) {
            return $matches[1];
        }

        return $value;
    }
}
