<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Internal\HeaderParser;

final class WordPressThemeReader extends AbstractFileReader
{
    private const HEADERS = [
        'Theme Name',
        'Theme URI',
        'Author',
        'Author URI',
        'Description',
        'Version',
        'Requires at least',
        'Tested up to',
        'Requires PHP',
        'License',
        'License URI',
        'Text Domain',
        'Tags',
        'Template',
        'Update URI',
    ];

    public function __construct(private readonly HeaderParser $parser = new HeaderParser())
    {
    }

    public function read(string $path): Document
    {
        return new Document(
            $this->parser->parse(substr($this->contents($path), 0, 8192), self::HEADERS),
            $path
        );
    }
}
