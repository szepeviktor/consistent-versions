<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;

final class ComposerReader extends JsonReader
{
    public function read(string $path): Document
    {
        $document = parent::read($path);
        if (!is_array($document->value())) {
            throw new ParseException(sprintf('Composer document must be a JSON object: %s', $path));
        }

        return $document;
    }
}
