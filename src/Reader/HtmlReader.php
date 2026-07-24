<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Internal\MarkupNode;
use SzepeViktor\ConsistentVersions\Internal\MarkupParser;

/**
 * @phpstan-type HtmlHeading array{level: int, text: string}
 * @phpstan-type HtmlLink array{text: string, url: string, title: string|null}
 * @phpstan-type HtmlDocument array{
 *     meta: array<string, string>,
 *     headings: list<HtmlHeading>,
 *     links: list<HtmlLink>,
 *     tree: MarkupNode
 * }
 */
final class HtmlReader extends AbstractFileReader
{
    public function __construct(private readonly MarkupParser $parser = new MarkupParser())
    {
    }

    public function read(string $path): Document
    {
        return new Document($this->readString($this->contents($path)), $path);
    }

    /**
     * @return HtmlDocument
     */
    public function readString(string $html): array
    {
        $tree = $this->parser->parse($html, true);
        $document = [
            'meta' => [],
            'headings' => [],
            'links' => [],
            'tree' => $tree,
        ];
        $this->collect($tree, $document);

        return $document;
    }

    /**
     * @param HtmlDocument $document
     */
    private function collect(MarkupNode $node, array &$document): void
    {
        $name = $node->name;
        $attributes = $node->attributes;

        if ($name === 'meta') {
            $key = $attributes['name'] ?? $attributes['property'] ?? null;
            if ($key !== null) {
                $document['meta'][$key] = $attributes['content'] ?? '';
            }
        } elseif (in_array($name, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
            $document['headings'][] = [
                'level' => (int) substr($name, 1),
                'text' => $this->nodeText($node),
            ];
        } elseif ($name === 'a') {
            $document['links'][] = [
                'text' => $this->nodeText($node),
                'url' => $attributes['href'] ?? '',
                'title' => $attributes['title'] ?? null,
            ];
        }

        foreach ($node->children as $child) {
            $this->collect($child, $document);
        }
    }

    private function nodeText(MarkupNode $node): string
    {
        $text = $node->text;
        foreach ($node->children as $child) {
            $text .= $this->nodeText($child);
        }

        return trim($text);
    }
}
