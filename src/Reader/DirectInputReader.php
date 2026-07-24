<?php

declare(strict_types=1);

namespace SzepeViktor\ConsistentVersions\Reader;

/**
 * A reader whose input is a named configuration field rather than a file path.
 */
interface DirectInputReader extends Reader
{
    public function inputField(): string;
}
