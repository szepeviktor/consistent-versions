<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;

final class ReaderRegistry
{
    /** @var array<string, Reader> */
    private array $readers = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register('json', new JsonReader());
        $registry->register('composer', new ComposerReader());
        $registry->register('yaml', new YamlReader());
        $registry->register('xml', new XmlReader());
        $registry->register('phpcs', new PhpcsReader());
        $registry->register('html', new HtmlReader());
        $registry->register('markdown', new MarkdownReader());
        $registry->register('wordpress-plugin', new WordPressPluginReader());
        $registry->register('wordpress-theme', new WordPressThemeReader());
        $registry->register('wordpress-readme', new WordPressReadmeReader());
        $registry->register('php', new PhpConstantReader());
        $registry->register('text', new TextReader());

        return $registry;
    }

    public function register(string $name, Reader $reader): void
    {
        $this->readers[$name] = $reader;
    }

    public function get(string $name): Reader
    {
        if (!isset($this->readers[$name])) {
            throw new ConfigurationException(sprintf(
                'Unknown reader "%s". Available readers: %s',
                $name,
                implode(', ', array_keys($this->readers))
            ));
        }

        return $this->readers[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->readers);
    }
}
