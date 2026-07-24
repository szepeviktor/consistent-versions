<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-type MarkdownHeading array{level: int, text: string}
 * @phpstan-type MarkdownLink array{text: string, url: string, title: string|null}
 * @phpstan-type MarkdownImage array{alt: string, url: string, title: string|null}
 * @phpstan-type MarkdownDocument array{
 *     frontMatter: array<array-key, mixed>,
 *     headings: list<MarkdownHeading>,
 *     links: list<MarkdownLink>,
 *     images: list<MarkdownImage>,
 *     html: array<array-key, mixed>,
 *     text: string
 * }
 */
final class MarkdownReader extends AbstractFileReader
{
    public function __construct(private readonly HtmlReader $htmlReader = new HtmlReader())
    {
    }

    public function read(string $path): Document
    {
        $contents = $this->contents($path);
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
        $document = [
            'frontMatter' => $this->frontMatter($lines, $path),
            'headings' => [],
            'links' => [],
            'images' => [],
            'html' => [],
            'text' => $contents,
        ];

        $inFence = false;
        $htmlLines = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '```') || str_starts_with($trimmed, '~~~')) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }

            $htmlLines[] = $line;
            $heading = $this->heading($line);
            if ($heading !== null) {
                $document['headings'][] = $heading;
            }
            $this->inlineDestinations($line, $document);
        }
        $document['html'] = $this->htmlReader->readString(implode("\n", $htmlLines));

        return new Document($document, $path);
    }

    /**
     * @param list<string> $lines
     * @return array<array-key, mixed>
     */
    private function frontMatter(array $lines, string $path): array
    {
        if (($lines[0] ?? null) !== '---') {
            return [];
        }

        $yaml = [];
        for ($index = 1, $count = count($lines); $index < $count; ++$index) {
            if ($lines[$index] === '---') {
                try {
                    $value = Yaml::parse(implode("\n", $yaml));
                } catch (SymfonyParseException $exception) {
                    throw new ParseException(
                        sprintf('Invalid Markdown front matter in %s: %s', $path, $exception->getMessage()),
                        0,
                        $exception
                    );
                }

                return is_array($value) ? $value : [];
            }
            $yaml[] = $lines[$index];
        }

        throw new ParseException(sprintf('Unclosed Markdown front matter in %s', $path));
    }

    /**
     * @return MarkdownHeading|null
     */
    private function heading(string $line): ?array
    {
        $line = ltrim($line);
        $level = 0;
        while (($line[$level] ?? '') === '#' && $level < 6) {
            ++$level;
        }
        if ($level === 0 || ($line[$level] ?? '') !== ' ') {
            return null;
        }

        return [
            'level' => $level,
            'text' => trim(substr($line, $level + 1), " \t#"),
        ];
    }

    /**
     * @param MarkdownDocument $document
     */
    private function inlineDestinations(string $line, array &$document): void
    {
        $length = strlen($line);
        for ($index = 0; $index < $length; ++$index) {
            $image = $line[$index] === '!' && ($line[$index + 1] ?? '') === '[';
            if (!$image && $line[$index] !== '[') {
                continue;
            }

            $labelStart = $index + ($image ? 2 : 1);
            $labelEnd = strpos($line, ']', $labelStart);
            if ($labelEnd === false || ($line[$labelEnd + 1] ?? '') !== '(') {
                continue;
            }
            $destinationEnd = strpos($line, ')', $labelEnd + 2);
            if ($destinationEnd === false) {
                continue;
            }

            $rawDestination = trim(substr($line, $labelEnd + 2, $destinationEnd - $labelEnd - 2));
            [$destination, $title] = $this->destination($rawDestination);
            $label = substr($line, $labelStart, $labelEnd - $labelStart);
            if ($image) {
                $document['images'][] = ['alt' => $label, 'url' => $destination, 'title' => $title];
            } else {
                $document['links'][] = ['text' => $label, 'url' => $destination, 'title' => $title];
            }
            $index = $destinationEnd;
        }
    }

    /**
     * @return array{string, string|null}
     */
    private function destination(string $raw): array
    {
        if (str_starts_with($raw, '<')) {
            $end = strpos($raw, '>');
            if ($end !== false) {
                return [substr($raw, 1, $end - 1), $this->title(trim(substr($raw, $end + 1)))];
            }
        }

        $space = strpos($raw, ' ');
        if ($space === false) {
            return [$raw, null];
        }

        return [substr($raw, 0, $space), $this->title(trim(substr($raw, $space + 1)))];
    }

    private function title(string $raw): ?string
    {
        if (strlen($raw) >= 2) {
            $first = $raw[0];
            $last = $raw[strlen($raw) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($raw, 1, -1);
            }
        }

        return $raw === '' ? null : $raw;
    }
}
