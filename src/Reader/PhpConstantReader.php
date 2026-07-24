<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Internal\PhpLexer;
use SzepeViktor\ConsistentVersions\Internal\UnresolvedValue;

/**
 * @phpstan-import-type PhpToken from PhpLexer
 * @phpstan-type ConstantValue string|int|float|bool|null
 * @phpstan-type ClassData array{constants: array<string, ConstantValue>}
 * @phpstan-type PhpDocument array{
 *     constants: array<string, ConstantValue>,
 *     classes: array<string, ClassData>
 * }
 */
final class PhpConstantReader extends AbstractFileReader
{
    private static ?UnresolvedValue $unresolved = null;

    public function __construct(private readonly PhpLexer $lexer = new PhpLexer())
    {
    }

    public function read(string $path): Document
    {
        $tokens = $this->lexer->tokenize($this->contents($path));
        /** @var PhpDocument $document */
        $document = ['constants' => [], 'classes' => []];
        $namespace = '';
        $braceDepth = 0;
        $pendingClass = null;
        /** @var list<array{name: string, depth: int}> $classes */
        $classes = [];

        for ($index = 0, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            $lower = $token['type'] === 'identifier' ? strtolower((string) $token['value']) : null;

            if ($lower === 'namespace' && ($tokens[$index + 1]['type'] ?? null) === 'identifier') {
                $namespace = trim((string) $tokens[++$index]['value'], '\\');
                continue;
            }

            if (in_array($lower, ['class', 'interface', 'trait', 'enum'], true)
                && ($tokens[$index + 1]['type'] ?? null) === 'identifier'
            ) {
                $shortName = (string) $tokens[++$index]['value'];
                $pendingClass = ltrim($namespace . '\\' . $shortName, '\\');
                $document['classes'][$pendingClass] ??= ['constants' => []];
                continue;
            }

            if ($token['value'] === '{') {
                ++$braceDepth;
                if ($pendingClass !== null) {
                    $classes[] = ['name' => $pendingClass, 'depth' => $braceDepth];
                    $pendingClass = null;
                }
                continue;
            }

            if ($token['value'] === '}') {
                if ($classes !== [] && $classes[array_key_last($classes)]['depth'] === $braceDepth) {
                    array_pop($classes);
                }
                --$braceDepth;
                continue;
            }

            if ($lower === 'const') {
                $class = $classes === [] ? null : $classes[array_key_last($classes)]['name'];
                $index = $this->readConstDeclaration($tokens, $index + 1, $namespace, $class, $document);
                continue;
            }

            if ($lower === 'define' && $classes === []) {
                $index = $this->readDefine($tokens, $index, $namespace, $document);
            }
        }

        return new Document($document, $path);
    }

    /**
     * @param list<PhpToken> $tokens
     * @param PhpDocument $document
     */
    private function readConstDeclaration(
        array $tokens,
        int $index,
        string $namespace,
        ?string $class,
        array &$document
    ): int {
        $count = count($tokens);
        while ($index < $count) {
            if (($tokens[$index]['type'] ?? null) !== 'identifier') {
                return $this->seek($tokens, $index, ';');
            }
            $name = (string) $tokens[$index]['value'];
            ++$index;
            if (($tokens[$index]['value'] ?? null) !== '=') {
                return $this->seek($tokens, $index, ';');
            }
            ++$index;
            [$value, $index] = $this->constantExpression($tokens, $index);
            if (!$value instanceof UnresolvedValue) {
                if ($class === null) {
                    $document['constants'][ltrim($namespace . '\\' . $name, '\\')] = $value;
                } else {
                    $document['classes'][$class]['constants'][$name] = $value;
                }
            }

            $delimiter = $tokens[$index]['value'] ?? null;
            if ($delimiter === ';') {
                return $index;
            }
            if ($delimiter !== ',') {
                return $this->seek($tokens, $index, ';');
            }
            ++$index;
        }

        return $index;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param PhpDocument $document
     */
    private function readDefine(array $tokens, int $index, string $namespace, array &$document): int
    {
        if (($tokens[$index + 1]['value'] ?? null) !== '('
            || ($tokens[$index + 2]['type'] ?? null) !== 'literal'
            || !is_string($tokens[$index + 2]['value'])
            || ($tokens[$index + 3]['value'] ?? null) !== ','
        ) {
            return $index;
        }

        $name = (string) $tokens[$index + 2]['value'];
        [$value, $end] = $this->constantExpression($tokens, $index + 4, [')']);
        if (!$value instanceof UnresolvedValue) {
            $document['constants'][ltrim($name, '\\')] = $value;
        }

        return $end;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param list<string> $delimiters
     * @return array{ConstantValue|UnresolvedValue, int}
     */
    private function constantExpression(array $tokens, int $index, array $delimiters = [',', ';']): array
    {
        $parts = [];
        $expectValue = true;
        for ($count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (in_array($token['value'], $delimiters, true)) {
                break;
            }
            if ($expectValue && $token['type'] === 'literal') {
                $parts[] = $token['value'];
                $expectValue = false;
                continue;
            }
            if (!$expectValue && $token['value'] === '.') {
                $expectValue = true;
                continue;
            }

            return [self::unresolved(), $this->seekAny($tokens, $index, $delimiters)];
        }

        if ($parts === [] || $expectValue) {
            return [self::unresolved(), $index];
        }
        if (count($parts) === 1) {
            return [$parts[0], $index];
        }

        return [implode('', array_map(static fn (mixed $part): string => (string) $part, $parts)), $index];
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function seek(array $tokens, int $index, string $value): int
    {
        return $this->seekAny($tokens, $index, [$value]);
    }

    /**
     * @param list<PhpToken> $tokens
     * @param list<string> $values
     */
    private function seekAny(array $tokens, int $index, array $values): int
    {
        for ($count = count($tokens); $index < $count; ++$index) {
            if (in_array($tokens[$index]['value'], $values, true)) {
                return $index;
            }
        }

        return $index;
    }

    private static function unresolved(): UnresolvedValue
    {
        return self::$unresolved ??= new UnresolvedValue();
    }
}
