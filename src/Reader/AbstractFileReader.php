<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Exception\ParseException;

abstract class AbstractFileReader implements Reader
{
    protected function contents(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ParseException(sprintf('File is not readable: %s', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ParseException(sprintf('Could not read file: %s', $path));
        }

        return $contents;
    }
}
