<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;
use SzepeViktor\ConsistentVersions\Internal\MarkupParser;

class XmlReader extends AbstractFileReader
{
    public function __construct(private readonly MarkupParser $parser = new MarkupParser())
    {
    }

    public function read(string $path): Document
    {
        try {
            return new Document($this->parser->parse($this->contents($path)), $path);
        } catch (ParseException $exception) {
            throw new ParseException(
                sprintf('Invalid XML in %s: %s', $path, $exception->getMessage()),
                0,
                $exception
            );
        }
    }
}
