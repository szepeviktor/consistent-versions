<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Internal\HeaderParser;

final class WordPressReadmeReader extends AbstractFileReader
{
    private const HEADERS = [
        'Contributors',
        'Donate link',
        'Tags',
        'Requires at least',
        'Tested up to',
        'Requires PHP',
        'Stable tag',
        'License',
        'License URI',
    ];

    public function __construct(private readonly HeaderParser $parser = new HeaderParser())
    {
    }

    public function read(string $path): Document
    {
        $contents = $this->contents($path);
        $document = $this->parser->parse($contents, self::HEADERS, true);
        $document['Name'] = $this->pluginName($contents);

        return new Document($document, $path);
    }

    private function pluginName(string $contents): ?string
    {
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $contents)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '===') && str_ends_with($line, '===')) {
                return trim($line, "= \t");
            }
            if ($line !== '') {
                break;
            }
        }

        return null;
    }
}
