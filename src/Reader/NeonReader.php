<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;

final class NeonReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        try {
            $value = Neon::decode($this->contents($path));
        } catch (NeonException $exception) {
            throw new ParseException(
                sprintf('Invalid NEON in %s: %s', $path, $exception->getMessage()),
                0,
                $exception
            );
        }

        return new Document($value, $path);
    }
}
