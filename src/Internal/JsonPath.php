<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Internal;

use SzepeViktor\ConsistentVersions\Exception\SelectionException;

/**
 * Deterministic, dependency-free JSONPath evaluator.
 *
 * Supported selectors are root/current nodes, dot and bracket member access,
 * array indexes, wildcards, recursive descent, and filter comparisons joined
 * by &&, ||, and !. The syntax is a deliberately read-only subset of RFC 9535.
 *
 * @phpstan-type MemberSegment array{type: 'member', value: string}
 * @phpstan-type IndexSegment array{type: 'index', value: int}
 * @phpstan-type WildcardSegment array{type: 'wildcard'}
 * @phpstan-type SimpleSegment MemberSegment|IndexSegment|WildcardSegment
 * @phpstan-type FilterSegment array{type: 'filter', expression: string}
 * @phpstan-type RecursiveSegment array{type: 'recursive', selector: SimpleSegment}
 * @phpstan-type Segment SimpleSegment|FilterSegment|RecursiveSegment
 * @phpstan-type Operand array{found: bool, value: mixed}
 */
final class JsonPath
{
    private int $offset = 0;

    private int $length;

    /**
     * @return list<mixed>
     */
    public function find(mixed $document, string $query): array
    {
        $segments = $this->parse($query);
        return $this->evaluate([$document], $segments, $document);
    }

    /**
     * @return list<Segment>
     */
    private function parse(string $query): array
    {
        $this->offset = 0;
        $this->length = strlen($query);
        if ($query === '' || ($query[0] !== '$' && $query[0] !== '@')) {
            throw new SelectionException('JSONPath must start with "$" or "@"');
        }
        ++$this->offset;
        $segments = [];

        while ($this->offset < $this->length) {
            if ($query[$this->offset] === '.') {
                ++$this->offset;
                $recursive = false;
                if (($query[$this->offset] ?? '') === '.') {
                    $recursive = true;
                    ++$this->offset;
                }

                if (($query[$this->offset] ?? '') === '*') {
                    ++$this->offset;
                    $segment = ['type' => 'wildcard'];
                } else {
                    $name = $this->readDotName($query);
                    if ($name === '') {
                        throw $this->syntax('Expected a member name after "."');
                    }
                    $segment = ['type' => 'member', 'value' => $name];
                }
                if ($recursive) {
                    $segment = ['type' => 'recursive', 'selector' => $segment];
                }
                $segments[] = $segment;
                continue;
            }

            if ($query[$this->offset] === '[') {
                $content = trim($this->readBracket($query));
                if ($content === '*') {
                    $segments[] = ['type' => 'wildcard'];
                } elseif (str_starts_with($content, '?')) {
                    $segments[] = ['type' => 'filter', 'expression' => trim(substr($content, 1))];
                } elseif ($this->isQuoted($content)) {
                    $segments[] = ['type' => 'member', 'value' => $this->unquote($content)];
                } elseif ($this->isInteger($content)) {
                    $segments[] = ['type' => 'index', 'value' => (int) $content];
                } else {
                    throw $this->syntax(sprintf('Unsupported bracket selector [%s]', $content));
                }
                continue;
            }

            throw $this->syntax(sprintf('Unexpected character "%s"', $query[$this->offset]));
        }

        return $segments;
    }

    /**
     * @param list<mixed> $nodes
     * @param list<Segment> $segments
     * @return list<mixed>
     */
    private function evaluate(array $nodes, array $segments, mixed $root): array
    {
        foreach ($segments as $segment) {
            $next = [];
            foreach ($nodes as $node) {
                if ($segment['type'] === 'member') {
                    if (is_array($node) && array_key_exists($segment['value'], $node)) {
                        $next[] = $node[$segment['value']];
                    } elseif (is_object($node) && property_exists($node, $segment['value'])) {
                        $next[] = $node->{$segment['value']};
                    }
                } elseif ($segment['type'] === 'index') {
                    if (!is_array($node)) {
                        continue;
                    }
                    $index = $segment['value'];
                    if ($index < 0) {
                        $index = count($node) + $index;
                    }
                    if (array_key_exists($index, $node)) {
                        $next[] = $node[$index];
                    }
                } elseif ($segment['type'] === 'wildcard') {
                    if (is_array($node)) {
                        foreach ($node as $value) {
                            $next[] = $value;
                        }
                    } elseif (is_object($node)) {
                        foreach (get_object_vars($node) as $value) {
                            $next[] = $value;
                        }
                    }
                } elseif ($segment['type'] === 'filter') {
                    $candidates = is_array($node) ? $node : (is_object($node) ? get_object_vars($node) : []);
                    foreach ($candidates as $candidate) {
                        if ($this->filter($segment['expression'], $candidate, $root)) {
                            $next[] = $candidate;
                        }
                    }
                } elseif ($segment['type'] === 'recursive') {
                    $descendants = [];
                    $this->descendants($node, $descendants);
                    $next = array_merge($next, $this->evaluate(
                        $descendants,
                        [$segment['selector']],
                        $root
                    ));
                }
            }
            $nodes = $next;
        }

        return $nodes;
    }

    private function filter(string $expression, mixed $current, mixed $root): bool
    {
        $expression = $this->stripParentheses(trim($expression));
        $parts = $this->splitOperator($expression, '||');
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                if ($this->filter($part, $current, $root)) {
                    return true;
                }
            }
            return false;
        }

        $parts = $this->splitOperator($expression, '&&');
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                if (!$this->filter($part, $current, $root)) {
                    return false;
                }
            }
            return true;
        }

        if (str_starts_with($expression, '!')) {
            return !$this->filter(substr($expression, 1), $current, $root);
        }

        foreach (['==', '!=', '<=', '>=', '<', '>'] as $operator) {
            $position = $this->findTopLevel($expression, $operator);
            if ($position === null) {
                continue;
            }
            $left = $this->operand(substr($expression, 0, $position), $current, $root);
            $right = $this->operand(substr($expression, $position + strlen($operator)), $current, $root);
            if (!$left['found'] || !$right['found']) {
                return false;
            }

            if ($operator === '==') {
                return $left['value'] === $right['value'];
            }
            if ($operator === '!=') {
                return $left['value'] !== $right['value'];
            }

            return $this->orderedComparison($left['value'], $right['value'], $operator);
        }

        $operand = $this->operand($expression, $current, $root);
        return $operand['found'] && (bool) $operand['value'];
    }

    /**
     * @return Operand
     */
    private function operand(string $operand, mixed $current, mixed $root): array
    {
        $operand = trim($operand);
        if ($operand === '') {
            return ['found' => false, 'value' => null];
        }
        if ($operand[0] === '@' || $operand[0] === '$') {
            $base = $operand[0] === '@' ? $current : $root;
            $matches = $this->evaluate([$base], $this->parse($operand), $root);
            return ['found' => count($matches) === 1, 'value' => $matches[0] ?? null];
        }
        if ($this->isQuoted($operand)) {
            return ['found' => true, 'value' => $this->unquote($operand)];
        }
        if ($operand === 'true' || $operand === 'false') {
            return ['found' => true, 'value' => $operand === 'true'];
        }
        if ($operand === 'null') {
            return ['found' => true, 'value' => null];
        }
        if ($this->isNumber($operand)) {
            return ['found' => true, 'value' => str_contains($operand, '.') ? (float) $operand : (int) $operand];
        }

        throw new SelectionException(sprintf('Unsupported JSONPath filter operand "%s"', $operand));
    }

    /**
     * @param list<mixed> $descendants
     */
    private function descendants(mixed $node, array &$descendants): void
    {
        if (!is_array($node) && !is_object($node)) {
            return;
        }
        foreach (is_array($node) ? $node : get_object_vars($node) as $child) {
            $descendants[] = $child;
            $this->descendants($child, $descendants);
        }
    }

    private function readDotName(string $query): string
    {
        $start = $this->offset;
        while ($this->offset < $this->length) {
            $character = $query[$this->offset];
            if ($character === '.' || $character === '[') {
                break;
            }
            ++$this->offset;
        }

        return substr($query, $start, $this->offset - $start);
    }

    private function readBracket(string $query): string
    {
        ++$this->offset;
        $start = $this->offset;
        $depth = 1;
        $quote = null;
        $escaped = false;

        while ($this->offset < $this->length) {
            $character = $query[$this->offset];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '[') {
                ++$depth;
            } elseif ($character === ']') {
                --$depth;
                if ($depth === 0) {
                    $content = substr($query, $start, $this->offset - $start);
                    ++$this->offset;
                    return $content;
                }
            }
            ++$this->offset;
        }

        throw $this->syntax('Unclosed bracket selector');
    }

    /**
     * @return non-empty-list<string>
     */
    private function splitOperator(string $expression, string $operator): array
    {
        $parts = [];
        $start = 0;
        while (($position = $this->findTopLevel($expression, $operator, $start)) !== null) {
            $parts[] = trim(substr($expression, $start, $position - $start));
            $start = $position + strlen($operator);
        }
        if ($parts === []) {
            return [$expression];
        }
        $parts[] = trim(substr($expression, $start));

        return $parts;
    }

    private function findTopLevel(string $input, string $needle, int $start = 0): ?int
    {
        $quote = null;
        $escaped = false;
        $roundDepth = 0;
        $squareDepth = 0;
        for ($index = $start, $length = strlen($input); $index < $length; ++$index) {
            $character = $input[$index];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '(') {
                ++$roundDepth;
            } elseif ($character === ')') {
                --$roundDepth;
            } elseif ($character === '[') {
                ++$squareDepth;
            } elseif ($character === ']') {
                --$squareDepth;
            } elseif ($roundDepth === 0 && $squareDepth === 0
                && substr($input, $index, strlen($needle)) === $needle
            ) {
                return $index;
            }
        }

        return null;
    }

    private function stripParentheses(string $expression): string
    {
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
            $inner = substr($expression, 1, -1);
            if ($this->findTopLevel($inner, ')') !== null) {
                break;
            }
            $expression = trim($inner);
        }

        return $expression;
    }

    private function isQuoted(string $value): bool
    {
        if (strlen($value) < 2) {
            return false;
        }
        return ($value[0] === "'" && $value[strlen($value) - 1] === "'")
            || ($value[0] === '"' && $value[strlen($value) - 1] === '"');
    }

    private function unquote(string $value): string
    {
        $quote = $value[0];
        $content = substr($value, 1, -1);
        $result = '';
        $escaped = false;
        for ($index = 0, $length = strlen($content); $index < $length; ++$index) {
            $character = $content[$index];
            if (!$escaped && $character === '\\') {
                $escaped = true;
                continue;
            }
            if ($escaped) {
                $result .= match ($character) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '\\' => '\\',
                    default => $character === $quote ? $quote : $character,
                };
                $escaped = false;
                continue;
            }
            $result .= $character;
        }
        if ($escaped) {
            $result .= '\\';
        }

        return $result;
    }

    private function isInteger(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $start = $value[0] === '-' ? 1 : 0;
        if ($start === strlen($value)) {
            return false;
        }
        for ($index = $start, $length = strlen($value); $index < $length; ++$index) {
            if ($value[$index] < '0' || $value[$index] > '9') {
                return false;
            }
        }

        return true;
    }

    private function isNumber(string $value): bool
    {
        $dots = 0;
        $start = ($value[0] ?? '') === '-' ? 1 : 0;
        if ($start === strlen($value)) {
            return false;
        }
        for ($index = $start, $length = strlen($value); $index < $length; ++$index) {
            if ($value[$index] === '.') {
                ++$dots;
                if ($dots > 1) {
                    return false;
                }
            } elseif ($value[$index] < '0' || $value[$index] > '9') {
                return false;
            }
        }

        return true;
    }

    private function orderedComparison(mixed $left, mixed $right, string $operator): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return match ($operator) {
                '<' => $left < $right,
                '>' => $left > $right,
                '<=' => $left <= $right,
                '>=' => $left >= $right,
                default => false,
            };
        }
        if (is_string($left) && is_string($right)) {
            $comparison = strcmp($left, $right);
            return match ($operator) {
                '<' => $comparison < 0,
                '>' => $comparison > 0,
                '<=' => $comparison <= 0,
                '>=' => $comparison >= 0,
                default => false,
            };
        }

        return false;
    }

    private function syntax(string $message): SelectionException
    {
        return new SelectionException(sprintf('%s at character %d', $message, $this->offset));
    }
}
