<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Internal\HeaderParser;

final class WordPressPluginReader extends AbstractFileReader
{
    private const HEADERS = [
        'Plugin Name',
        'Plugin URI',
        'Description',
        'Version',
        'Requires at least',
        'Requires PHP',
        'Author',
        'Author URI',
        'License',
        'License URI',
        'Text Domain',
        'Domain Path',
        'Network',
        'Update URI',
        'Requires Plugins',
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
