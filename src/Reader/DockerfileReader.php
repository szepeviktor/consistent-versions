<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;

final class DockerfileReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        return new Document([
            'args' => $this->args($this->contents($path)),
        ], $path);
    }

    /**
     * @return array<string, string|null>
     */
    private function args(string $contents): array
    {
        $args = [];

        foreach ($this->instructions($contents) as $instruction) {
            if (strncasecmp($instruction, 'ARG ', 4) !== 0) {
                continue;
            }

            $definition = trim(substr($instruction, 4));
            if ($definition === '') {
                continue;
            }

            $parts = explode('=', $definition, 2);
            $name = trim($parts[0]);
            if ($name === '') {
                continue;
            }

            if (count($parts) === 1 && array_key_exists($name, $args)) {
                continue;
            }

            $args[$name] = $parts[1] ?? null;
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function instructions(string $contents): array
    {
        $instructions = [];
        $current = '';

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = $this->withoutComment($line);
            if (trim($line) === '') {
                continue;
            }

            if ($this->continues($line)) {
                $current .= rtrim(substr(rtrim($line), 0, -1)) . ' ';
                continue;
            }

            $instructions[] = trim($current . $line);
            $current = '';
        }

        if (trim($current) !== '') {
            $instructions[] = trim($current);
        }

        return $instructions;
    }

    private function continues(string $line): bool
    {
        return str_ends_with(rtrim($line), '\\');
    }

    private function withoutComment(string $line): string
    {
        $position = strpos(ltrim($line), '#');
        if ($position === 0) {
            return '';
        }

        return $line;
    }
}
