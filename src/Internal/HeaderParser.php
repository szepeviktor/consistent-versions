<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Internal;

final class HeaderParser
{
    /**
     * @param list<string> $knownHeaders
     * @return array<string, string|null>
     */
    public function parse(string $contents, array $knownHeaders, bool $stopAtSection = false): array
    {
        $values = array_fill_keys($knownHeaders, null);
        $lookup = [];
        foreach ($knownHeaders as $header) {
            $lookup[strtolower($header)] = $header;
        }

        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        foreach (explode("\n", $contents) as $line) {
            $line = $this->stripCommentDecoration($line);
            if ($stopAtSection && str_starts_with($line, '== ') && str_ends_with($line, ' ==')) {
                break;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $name = trim(substr($line, 0, $colon));
            $canonicalName = $lookup[strtolower($name)] ?? null;
            if ($canonicalName === null) {
                continue;
            }

            $values[$canonicalName] = trim(substr($line, $colon + 1));
        }

        return $values;
    }

    private function stripCommentDecoration(string $line): string
    {
        $line = trim($line);
        if (str_starts_with($line, '<?php')) {
            $line = trim(substr($line, 5));
        }

        while ($line !== '') {
            if (str_starts_with($line, '/*')) {
                $line = ltrim(substr($line, 2));
                continue;
            }
            if (str_starts_with($line, '*/')) {
                $line = ltrim(substr($line, 2));
                continue;
            }
            if ($line[0] === '*' || $line[0] === '#' || str_starts_with($line, '//')) {
                $line = ltrim(substr($line, str_starts_with($line, '//') ? 2 : 1));
                continue;
            }
            break;
        }

        if (str_ends_with($line, '*/')) {
            $line = rtrim(substr($line, 0, -2));
        }

        return $line;
    }
}
