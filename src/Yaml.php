<?php

declare(strict_types=1);

namespace SzepeViktor\CheckVersions;

use function yaml_parse_file;

class Yaml implements Check
{
    protected string $path;

    protected array $document;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->document = $this->parse();
    }

    public function parse(): array
    {
        $document = yaml_parse_file($this->path);
        // $document = []; $document['jobs']['run-all']['steps'][0]['with']['php-version'] = '7.0';

        if ($document === false) {
            throw new \RuntimeException('YAML parse error in ' . $this->path);
        }

        return $document;
    }
}
