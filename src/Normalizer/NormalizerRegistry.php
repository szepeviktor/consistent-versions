<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Normalizer;

use SzepeViktor\ConsistentVersions\Exception\ConfigurationException;

final class NormalizerRegistry
{
    /** @var array<string, Normalizer> */
    private array $normalizers = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register('identity', new CallbackNormalizer(
            static fn (string|int|float|bool|null $value): string|int|float|bool|null => $value
        ));
        $registry->register('string', new CallbackNormalizer(
            static function (string|int|float|bool|null $value): string {
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                return $value === null ? '' : (string) $value;
            }
        ));
        $registry->register('trim', new CallbackNormalizer(
            static function (string|int|float|bool|null $value): string|int|float|bool|null {
                return is_string($value) ? trim($value) : $value;
            }
        ));
        $registry->register('trim-v-prefix', new CallbackNormalizer(
            static function (string|int|float|bool|null $value): string|int|float|bool|null {
                return is_string($value) && str_starts_with($value, 'v') ? substr($value, 1) : $value;
            }
        ));
        $registry->register('version', new VersionNormalizer());
        $registry->register('composer-minimum', new ComposerMinimumNormalizer());

        return $registry;
    }

    public function register(string $name, Normalizer $normalizer): void
    {
        $this->normalizers[$name] = $normalizer;
    }

    /**
     * @param list<string> $names
     */
    public function normalize(
        string|int|float|bool|null $value,
        array $names
    ): string|int|float|bool|null {
        foreach ($names as $name) {
            if (!isset($this->normalizers[$name])) {
                throw new ConfigurationException(sprintf('Unknown normalizer "%s"', $name));
            }
            $value = $this->normalizers[$name]->normalize($value);
        }

        return $value;
    }
}
