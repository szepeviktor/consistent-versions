<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Document;
use SzepeViktor\ConsistentVersions\Exception\ParseException;
use Symfony\Component\Yaml\Exception\ParseException as SymfonyParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlReader extends AbstractFileReader
{
    public function read(string $path): Document
    {
        try {
            $value = Yaml::parse($this->contents($path));
        } catch (SymfonyParseException $exception) {
            throw new ParseException(
                sprintf('Invalid YAML in %s: %s', $path, $exception->getMessage()),
                0,
                $exception
            );
        }

        return new Document($value, $path);
    }
}
