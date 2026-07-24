<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;

final class TextReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        return new Document(trim($this->contents($path)), $path);
    }
}
