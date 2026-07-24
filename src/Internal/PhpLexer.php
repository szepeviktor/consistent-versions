<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Internal;

use SzepeViktor\ConsistentVersions\Exception\ParseException;

/**
 * @phpstan-type LiteralToken array{type: 'literal', value: string|int|float|bool|null}
 * @phpstan-type StringToken array{type: 'identifier'|'symbol', value: string}
 * @phpstan-type PhpToken LiteralToken|StringToken
 */
final class PhpLexer
{
    /**
     * @return list<PhpToken>
     */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            $character = $source[$offset];
            if ($this->isWhitespace($character)) {
                ++$offset;
                continue;
            }
            if ($character === '<' && substr($source, $offset, 5) === '<?php') {
                $offset += 5;
                continue;
            }
            if ($character === '<' && substr($source, $offset, 3) === '<?=') {
                $offset += 3;
                continue;
            }
            if ($character === '?' && ($source[$offset + 1] ?? '') === '>') {
                $offset += 2;
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '/') {
                $offset = $this->lineEnd($source, $offset + 2);
                continue;
            }
            if ($character === '#') {
                $offset = $this->lineEnd($source, $offset + 1);
                continue;
            }
            if ($character === '/' && ($source[$offset + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $offset + 2);
                if ($end === false) {
                    throw new ParseException('Unclosed PHP block comment');
                }
                $offset = $end + 2;
                continue;
            }
            if ($character === '"' || $character === "'") {
                [$value, $offset] = $this->string($source, $offset, $character);
                $tokens[] = ['type' => 'literal', 'value' => $value];
                continue;
            }
            if ($this->isIdentifierStart($character)) {
                $start = $offset;
                ++$offset;
                while ($offset < $length && $this->isIdentifierCharacter($source[$offset])) {
                    ++$offset;
                }
                $value = substr($source, $start, $offset - $start);
                $lower = strtolower($value);
                if ($lower === 'true' || $lower === 'false') {
                    $tokens[] = ['type' => 'literal', 'value' => $lower === 'true'];
                } elseif ($lower === 'null') {
                    $tokens[] = ['type' => 'literal', 'value' => null];
                } else {
                    $tokens[] = ['type' => 'identifier', 'value' => $value];
                }
                continue;
            }
            if ($this->isDigit($character)) {
                $start = $offset;
                ++$offset;
                while ($offset < $length && ($this->isDigit($source[$offset]) || $source[$offset] === '.')) {
                    ++$offset;
                }
                $number = substr($source, $start, $offset - $start);
                $tokens[] = [
                    'type' => 'literal',
                    'value' => str_contains($number, '.') ? (float) $number : (int) $number,
                ];
                continue;
            }

            $tokens[] = ['type' => 'symbol', 'value' => $character];
            ++$offset;
        }

        return $tokens;
    }

    /**
     * @return array{string, int}
     */
    private function string(string $source, int $offset, string $quote): array
    {
        ++$offset;
        $value = '';
        $length = strlen($source);
        while ($offset < $length) {
            $character = $source[$offset];
            if ($character === $quote) {
                return [$value, $offset + 1];
            }
            if ($character !== '\\') {
                $value .= $character;
                ++$offset;
                continue;
            }

            ++$offset;
            if ($offset >= $length) {
                break;
            }
            $escaped = $source[$offset];
            if ($quote === "'") {
                $value .= ($escaped === "'" || $escaped === '\\') ? $escaped : '\\' . $escaped;
            } else {
                $value .= match ($escaped) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'v' => "\v",
                    'e' => "\x1B",
                    'f' => "\f",
                    default => $escaped,
                };
            }
            ++$offset;
        }

        throw new ParseException('Unclosed PHP string literal');
    }

    private function lineEnd(string $source, int $offset): int
    {
        $end = strpos($source, "\n", $offset);
        return $end === false ? strlen($source) : $end + 1;
    }

    private function isWhitespace(string $character): bool
    {
        return $character === ' ' || $character === "\t" || $character === "\r" || $character === "\n";
    }

    private function isIdentifierStart(string $character): bool
    {
        $ord = ord($character);
        return $character === '_' || $character === '\\'
            || ($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122) || $ord >= 128;
    }

    private function isIdentifierCharacter(string $character): bool
    {
        return $this->isIdentifierStart($character) || $this->isDigit($character);
    }

    private function isDigit(string $character): bool
    {
        $ord = ord($character);
        return $ord >= 48 && $ord <= 57;
    }
}
