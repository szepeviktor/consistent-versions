<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Internal;

final class MarkupNode
{
    /** @var list<self> */
    public array $children = [];

    public string $text = '';

    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $attributes = []
    ) {
    }
}
