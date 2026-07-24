<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;

final class IniReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        $value = @parse_ini_string($this->contents($path), true, INI_SCANNER_RAW);
        if ($value === false) {
            throw new ParseException(sprintf('Invalid INI in %s', $path));
        }

        return new Document($value, $path);
    }
}
