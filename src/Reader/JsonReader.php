<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use JsonException;
use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;

class JsonReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        try {
            $value = json_decode($this->contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException(
                sprintf('Invalid JSON in %s: %s', $path, $exception->getMessage()),
                0,
                $exception
            );
        }

        return new Document($value, $path);
    }
}
