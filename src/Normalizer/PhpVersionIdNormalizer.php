<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;

final class PhpVersionIdNormalizer implements Normalizer
{
    public function normalize(string|int|float|bool|null $value): string
    {
        if (
            (!is_int($value) && !is_string($value))
            || (is_string($value) && ($value === '' || strspn($value, '0123456789') !== strlen($value)))
        ) {
            throw new ConfigurationException('The "php-version-id" normalizer expects an integer');
        }

        $versionId = (int) $value;
        $major = intdiv($versionId, 10000);
        $minor = intdiv($versionId % 10000, 100);
        $patch = $versionId % 100;

        if ($major < 1) {
            throw new ConfigurationException(sprintf('Invalid PHP_VERSION_ID "%s"', (string) $value));
        }

        return $patch === 0
            ? sprintf('%d.%d', $major, $minor)
            : sprintf('%d.%d.%d', $major, $minor, $patch);
    }
}
