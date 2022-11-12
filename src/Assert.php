<?php

declare(strict_types=1);

namespace SzepeViktor\CheckVersions;

trait Assert
{
    protected string $id;

    public function assertEqual($first, $second): void
    {
        if ($first !== $second) {
            Engine::addError(sprintf(
                '%s: "%s" is not equal to "%s"',
                $this->id ?? 'N/A',
                $first,
                $second
            ));;
        }
    }
}
