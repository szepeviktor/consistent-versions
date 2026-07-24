<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

use JsonException;
use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConfigurationLoader
{
    /**
     * @return array<array-key, mixed>
     */
    public function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException(sprintf('Configuration file is not readable: %s', $path));
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ConfigurationException(sprintf('Could not read configuration file: %s', $path));
        }

        try {
            if (str_ends_with(strtolower($path), '.json')) {
                $configuration = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $configuration = Yaml::parse($contents);
            }
        } catch (JsonException|ParseException $exception) {
            throw new ConfigurationException(
                sprintf('Invalid configuration %s: %s', $path, $exception->getMessage()),
                0,
                $exception
            );
        }

        if (!is_array($configuration)) {
            throw new ConfigurationException(sprintf('Configuration root must be an object: %s', $path));
        }

        return $configuration;
    }
}
