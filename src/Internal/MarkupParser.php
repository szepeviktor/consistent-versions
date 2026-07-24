<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Internal;

use SzepeViktor\ConsistentVersions\Exception\ParseException;

/**
 * Small, dependency-free XML/HTML tokenizer.
 *
 * It deliberately exposes a stable tree instead of PHP's environment-dependent
 * DOM objects. XML entities are limited to predefined and numeric entities;
 * external entities are never loaded.
 */
final class MarkupParser
{
    private int $offset = 0;

    private int $length;

    private string $input;

    public function parse(string $input, bool $html = false): MarkupNode
    {
        $this->input = $input;
        $this->length = strlen($input);
        $this->offset = 0;

        $root = $this->node('#document');
        /** @var non-empty-list<MarkupNode> $stack */
        $stack = [$root];

        while ($this->offset < $this->length) {
            if ($this->startsWith('<!--')) {
                $this->skipUntil('-->', 'Unclosed markup comment');
                continue;
            }

            if ($this->startsWith('<?')) {
                $this->skipUntil('?>', 'Unclosed processing instruction');
                continue;
            }

            if ($this->startsWith('<![CDATA[')) {
                $this->offset += 9;
                $text = $this->takeUntil(']]>', 'Unclosed CDATA section');
                $this->currentNode($stack)->text .= $text;
                continue;
            }

            if ($this->startsWith('<!')) {
                $this->skipDeclaration();
                continue;
            }

            if ($this->startsWith('</')) {
                $this->offset += 2;
                $name = $this->readName();
                $this->skipWhitespace();
                $this->expect('>');

                if (count($stack) === 1) {
                    if ($html) {
                        continue;
                    }
                    throw $this->error(sprintf('Unexpected closing tag </%s>', $name));
                }

                if (!$html && $this->currentNode($stack)->name !== $name) {
                    throw $this->error(sprintf(
                        'Closing tag </%s> does not match <%s>',
                        $name,
                        $this->currentNode($stack)->name
                    ));
                }

                if ($html) {
                    while (count($stack) > 1) {
                        $openName = strtolower($this->currentNode($stack)->name);
                        array_pop($stack);
                        if ($openName === strtolower($name)) {
                            break;
                        }
                    }
                } else {
                    array_pop($stack);
                }
                continue;
            }

            if ($this->current() === '<' && $html && !$this->isNameCharacter($this->input[$this->offset + 1] ?? '')) {
                $this->currentNode($stack)->text .= '<';
                ++$this->offset;
                continue;
            }

            if ($this->current() === '<') {
                ++$this->offset;
                $name = $this->readName();
                $attributes = $this->readAttributes($html);
                $selfClosing = false;

                if ($this->startsWith('/>')) {
                    $this->offset += 2;
                    $selfClosing = true;
                } else {
                    $this->expect('>');
                }

                $node = $this->node($html ? strtolower($name) : $name, $attributes);
                $this->currentNode($stack)->children[] = $node;

                if (!$selfClosing && !($html && $this->isVoidHtmlElement($name))) {
                    $stack[] = $node;
                }
                continue;
            }

            $text = $this->readText();
            $this->currentNode($stack)->text .= $this->decodeEntities($text, $html);
        }

        if (!$html && count($stack) !== 1) {
            throw $this->error(sprintf('Unclosed tag <%s>', $this->currentNode($stack)->name));
        }

        if (!$html && count($root->children) !== 1) {
            throw $this->error('An XML document must contain exactly one root element');
        }

        return $html ? $root : $root->children[0];
    }

    /**
     * @param array<string, string> $attributes
     */
    private function node(string $name, array $attributes = []): MarkupNode
    {
        return new MarkupNode($name, $attributes);
    }

    /**
     * @return array<string, string>
     */
    private function readAttributes(bool $html): array
    {
        $attributes = [];

        while ($this->offset < $this->length) {
            $this->skipWhitespace();
            if ($this->startsWith('/>') || $this->current() === '>') {
                break;
            }

            $name = $this->readName();
            $this->skipWhitespace();
            if ($this->current() !== '=') {
                if (!$html) {
                    throw $this->error(sprintf('Attribute %s has no value', $name));
                }
                $attributes[strtolower($name)] = '';
                continue;
            }

            ++$this->offset;
            $this->skipWhitespace();
            $quote = $this->current();
            if ($quote !== '"' && $quote !== "'") {
                if (!$html) {
                    throw $this->error(sprintf('Attribute %s must be quoted', $name));
                }
                $value = $this->readUnquotedAttribute();
            } else {
                ++$this->offset;
                $start = $this->offset;
                while ($this->offset < $this->length && $this->current() !== $quote) {
                    ++$this->offset;
                }
                if ($this->offset >= $this->length) {
                    throw $this->error(sprintf('Unclosed attribute %s', $name));
                }
                $value = substr($this->input, $start, $this->offset - $start);
                ++$this->offset;
            }

            $attributes[$html ? strtolower($name) : $name] = $this->decodeEntities($value, $html);
        }

        return $attributes;
    }

    private function readUnquotedAttribute(): string
    {
        $start = $this->offset;
        while ($this->offset < $this->length) {
            $character = $this->current();
            if ($this->isWhitespace($character) || $character === '>' || $character === '/') {
                break;
            }
            ++$this->offset;
        }

        return substr($this->input, $start, $this->offset - $start);
    }

    private function readName(): string
    {
        $start = $this->offset;
        while ($this->offset < $this->length && $this->isNameCharacter($this->current())) {
            ++$this->offset;
        }

        if ($start === $this->offset) {
            throw $this->error('Expected a tag or attribute name');
        }

        return substr($this->input, $start, $this->offset - $start);
    }

    private function readText(): string
    {
        $start = $this->offset;
        while ($this->offset < $this->length && $this->current() !== '<') {
            ++$this->offset;
        }

        return substr($this->input, $start, $this->offset - $start);
    }

    private function skipWhitespace(): void
    {
        while ($this->offset < $this->length && $this->isWhitespace($this->current())) {
            ++$this->offset;
        }
    }

    private function skipDeclaration(): void
    {
        $depth = 0;
        $quote = null;
        while ($this->offset < $this->length) {
            $character = $this->current();
            ++$this->offset;
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '[') {
                ++$depth;
            } elseif ($character === ']') {
                --$depth;
            } elseif ($character === '>' && $depth <= 0) {
                return;
            }
        }

        throw $this->error('Unclosed declaration');
    }

    private function skipUntil(string $needle, string $message): void
    {
        $this->offset += strlen($needle === '-->' ? '<!--' : '<?');
        $this->takeUntil($needle, $message);
    }

    private function takeUntil(string $needle, string $message): string
    {
        $position = strpos($this->input, $needle, $this->offset);
        if ($position === false) {
            throw $this->error($message);
        }

        $value = substr($this->input, $this->offset, $position - $this->offset);
        $this->offset = $position + strlen($needle);

        return $value;
    }

    private function expect(string $character): void
    {
        if ($this->current() !== $character) {
            throw $this->error(sprintf('Expected "%s"', $character));
        }
        ++$this->offset;
    }

    private function current(): string
    {
        return $this->input[$this->offset] ?? '';
    }

    private function startsWith(string $value): bool
    {
        return substr($this->input, $this->offset, strlen($value)) === $value;
    }

    private function isWhitespace(string $character): bool
    {
        return $character === ' ' || $character === "\t" || $character === "\r" || $character === "\n";
    }

    private function isNameCharacter(string $character): bool
    {
        if ($character === '' || $this->isWhitespace($character)) {
            return false;
        }

        return $character !== '/' && $character !== '>' && $character !== '='
            && $character !== '"' && $character !== "'" && $character !== '<';
    }

    private function isVoidHtmlElement(string $name): bool
    {
        return in_array(strtolower($name), [
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
    }

    private function decodeEntities(string $value, bool $html): string
    {
        $named = [
            '&amp;' => '&',
            '&lt;' => '<',
            '&gt;' => '>',
            '&quot;' => '"',
            '&apos;' => "'",
        ];
        if ($html) {
            $named['&nbsp;'] = "\xC2\xA0";
        }
        $value = strtr($value, $named);

        $output = '';
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            if ($value[$index] !== '&' || ($value[$index + 1] ?? '') !== '#') {
                $output .= $value[$index];
                continue;
            }

            $end = strpos($value, ';', $index + 2);
            if ($end === false) {
                $output .= $value[$index];
                continue;
            }
            $encoded = substr($value, $index + 2, $end - $index - 2);
            $hex = ($encoded[0] ?? '') === 'x' || ($encoded[0] ?? '') === 'X';
            if ($hex) {
                $encoded = substr($encoded, 1);
            }
            if ($encoded === '' || !$this->isDigits($encoded, $hex)) {
                $output .= $value[$index];
                continue;
            }
            $output .= $this->codePoint((int) ($hex ? hexdec($encoded) : $encoded));
            $index = $end;
        }

        return $output;
    }

    private function isDigits(string $value, bool $hex): bool
    {
        $allowed = $hex ? '0123456789abcdefABCDEF' : '0123456789';
        for ($index = 0, $length = strlen($value); $index < $length; ++$index) {
            if (strpos($allowed, $value[$index]) === false) {
                return false;
            }
        }

        return true;
    }

    private function codePoint(int $point): string
    {
        if ($point <= 0x7F) {
            return chr($point);
        }
        if ($point <= 0x7FF) {
            return chr(0xC0 | ($point >> 6)) . chr(0x80 | ($point & 0x3F));
        }
        if ($point <= 0xFFFF) {
            return chr(0xE0 | ($point >> 12))
                . chr(0x80 | (($point >> 6) & 0x3F))
                . chr(0x80 | ($point & 0x3F));
        }

        return chr(0xF0 | ($point >> 18))
            . chr(0x80 | (($point >> 12) & 0x3F))
            . chr(0x80 | (($point >> 6) & 0x3F))
            . chr(0x80 | ($point & 0x3F));
    }

    /**
     * @param list<MarkupNode> $stack
     */
    private function currentNode(array $stack): MarkupNode
    {
        if ($stack === []) {
            throw $this->error('Internal parser stack is empty');
        }

        return $stack[count($stack) - 1];
    }

    private function error(string $message): ParseException
    {
        return new ParseException(sprintf('%s at byte %d', $message, $this->offset));
    }
}
