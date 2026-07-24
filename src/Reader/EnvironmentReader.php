<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;

final class EnvironmentReader implements DirectInputReader
{
    public function inputField(): string
    {
        return 'variable';
    }

    public function read(string $variable): Document
    {
        if ($variable === '' || strcspn($variable, "=\0") !== strlen($variable)) {
            throw new ParseException(sprintf('Invalid environment variable name "%s"', $variable));
        }

        $value = getenv($variable);
        if ($value === false) {
            throw new ParseException(sprintf('Environment variable "%s" is not set', $variable));
        }

        return new Document($value, sprintf('environment variable %s', $variable));
    }
}
