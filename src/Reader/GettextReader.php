<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;

/**
 * @phpstan-type GettextDocument array{
 *     headers: array<string, string>,
 *     project: array{name: string|null, version: string|null}
 * }
 */
final class GettextReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        $header = $this->headerEntry($this->contents($path), $path);
        $headers = $this->headers($header);
        [$projectName, $projectVersion] = $this->project($headers['Project-Id-Version'] ?? null);

        /** @var GettextDocument $document */
        $document = [
            'headers' => $headers,
            'project' => [
                'name' => $projectName,
                'version' => $projectVersion,
            ],
        ];

        return new Document($document, $path);
    }

    private function headerEntry(string $contents, string $path): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));
        $foundEmptyMessageId = false;
        $readingHeader = false;
        $header = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'msgid ')) {
                if ($readingHeader) {
                    break;
                }
                $foundEmptyMessageId = $this->quoted(substr($line, 6), $path) === '';
                continue;
            }

            if ($foundEmptyMessageId && str_starts_with($line, 'msgstr ')) {
                $header = $this->quoted(substr($line, 7), $path);
                $readingHeader = true;
                continue;
            }

            if ($readingHeader && str_starts_with($line, '"')) {
                $header .= $this->quoted($line, $path);
                continue;
            }

            if ($readingHeader && $line !== '') {
                break;
            }
        }

        if (!$readingHeader) {
            throw new ParseException(sprintf('Gettext header entry is missing in %s', $path));
        }

        return $header;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $header): array
    {
        $headers = [];
        foreach (explode("\n", $header) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separator));
            if ($name === '') {
                continue;
            }
            $headers[$name] = ltrim(substr($line, $separator + 1));
        }

        return $headers;
    }

    /**
     * @return array{string|null, string|null}
     */
    private function project(?string $projectIdVersion): array
    {
        if ($projectIdVersion === null || trim($projectIdVersion) === '') {
            return [null, null];
        }

        $projectIdVersion = trim($projectIdVersion);
        for ($index = strlen($projectIdVersion) - 1; $index >= 0; --$index) {
            if ($projectIdVersion[$index] !== ' ' && $projectIdVersion[$index] !== "\t") {
                continue;
            }

            $name = rtrim(substr($projectIdVersion, 0, $index));
            $version = ltrim(substr($projectIdVersion, $index + 1));
            if ($name !== '' && $version !== '') {
                return [$name, $version];
            }
        }

        return [$projectIdVersion, null];
    }

    private function quoted(string $value, string $path): string
    {
        $value = trim($value);
        if (strlen($value) < 2 || $value[0] !== '"' || $value[strlen($value) - 1] !== '"') {
            throw new ParseException(sprintf('Invalid Gettext string in %s', $path));
        }

        return stripcslashes(substr($value, 1, -1));
    }
}
