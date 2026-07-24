<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;
use SzepeViktor\ConsistentVersions\Internal\MarkupNode;

/**
 * @phpstan-type PhpcsRule array<string, mixed>
 * @phpstan-type PhpcsDocument array{
 *     name: string|null,
 *     description: string|null,
 *     config: array<string, string>,
 *     arguments: list<array<string, string>>,
 *     rules: list<PhpcsRule>
 * }
 */
final class PhpcsReader extends XmlReader
{
    public function read(string $path): Document
    {
        $xml = parent::read($path)->value();
        if (!$xml instanceof MarkupNode || $xml->name !== 'ruleset') {
            throw new ParseException(sprintf('PHPCS XML root must be <ruleset>: %s', $path));
        }

        $document = $this->emptyDocument($xml->attributes['name'] ?? null);
        foreach ($xml->children as $child) {
            if ($child->name === 'description') {
                $document['description'] = trim($child->text);
            } elseif ($child->name === 'config' && isset($child->attributes['name'])) {
                $document['config'][$child->attributes['name']] = $child->attributes['value'] ?? '';
            } elseif ($child->name === 'arg') {
                $document['arguments'][] = $child->attributes;
            } elseif ($child->name === 'rule') {
                $document['rules'][] = $child->attributes + ['children' => $child->children];
            }
        }

        return new Document($document, $path);
    }

    /**
     * @return PhpcsDocument
     */
    private function emptyDocument(?string $name): array
    {
        return [
            'name' => $name,
            'description' => null,
            'config' => [],
            'arguments' => [],
            'rules' => [],
        ];
    }
}
