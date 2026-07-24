<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions;

use SzepeViktor\ConsistentVersions\Exception\SelectionException;
use SzepeViktor\ConsistentVersions\Internal\JsonPath;
use Throwable;

final class Selector
{
    public function __construct(private readonly JsonPath $jsonPath = new JsonPath())
    {
    }

    public function select(Document $document, string $path = '$'): string|int|float|bool|null
    {
        try {
            $matches = $this->jsonPath->find($document->value(), $path);
        } catch (Throwable $exception) {
            throw new SelectionException(
                sprintf('Invalid JSONPath "%s" for %s: %s', $path, $document->origin(), $exception->getMessage()),
                0,
                $exception
            );
        }

        if (count($matches) !== 1) {
            throw new SelectionException(sprintf(
                'JSONPath "%s" must select exactly one value in %s; selected %d',
                $path,
                $document->origin(),
                count($matches)
            ));
        }

        $value = $matches[0];
        if (!is_scalar($value) && $value !== null) {
            throw new SelectionException(sprintf(
                'JSONPath "%s" must select a scalar value in %s; selected %s',
                $path,
                $document->origin(),
                get_debug_type($value)
            ));
        }

        return $value;
    }
}
