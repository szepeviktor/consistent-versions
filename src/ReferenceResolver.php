<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;
use SzepeViktor\ConsistentVersions\Normalizer\NormalizerRegistry;
use SzepeViktor\ConsistentVersions\Reader\DirectInputReader;
use SzepeViktor\ConsistentVersions\Reader\ReaderRegistry;

final class ReferenceResolver
{
    public function __construct(
        private readonly ReaderRegistry $readers,
        private readonly NormalizerRegistry $normalizers,
        private readonly Selector $selector
    ) {
    }

    /**
     * @param mixed $reference
     * @param list<string> $commonNormalizers
     */
    public function resolve(
        mixed $reference,
        string $baseDirectory,
        array $commonNormalizers = []
    ): string|int|float|bool|null {
        if (!is_array($reference)) {
            if (!is_scalar($reference) && $reference !== null) {
                throw new ConfigurationException('A literal expected value must be scalar');
            }

            return $this->normalizers->normalize($reference, $commonNormalizers);
        }

        $readerName = $reference['reader'] ?? null;
        $path = $reference['path'] ?? '$';
        if (!is_string($readerName) || !is_string($path)) {
            throw new ConfigurationException('A source requires a string "reader" and optional "path" field');
        }
        $reader = $this->readers->get($readerName);
        $inputField = $reader instanceof DirectInputReader ? $reader->inputField() : 'file';
        $input = $reference[$inputField] ?? null;
        if (!is_string($input)) {
            throw new ConfigurationException(sprintf(
                'Reader "%s" requires a string "%s" field',
                $readerName,
                $inputField
            ));
        }
        if (!$reader instanceof DirectInputReader) {
            $input = $this->absolutePath($baseDirectory, $input);
        }

        $normalizers = $reference['normalize'] ?? [];
        if (is_string($normalizers)) {
            $normalizers = [$normalizers];
        }
        if (!is_array($normalizers)) {
            throw new ConfigurationException('Source "normalize" must be a string or list');
        }
        $normalizerNames = [];
        foreach ($normalizers as $normalizer) {
            if (!is_string($normalizer)) {
                throw new ConfigurationException('Source normalizers must be strings');
            }
            $normalizerNames[] = $normalizer;
        }

        $document = $reader->read($input);
        $value = $this->selector->select($document, $path);
        $value = $this->normalizers->normalize($value, $normalizerNames);

        return $this->normalizers->normalize($value, $commonNormalizers);
    }

    private function absolutePath(string $baseDirectory, string $path): string
    {
        if ($path !== '' && ($path[0] === '/' || (isset($path[1]) && $path[1] === ':'))) {
            return $path;
        }

        return rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
