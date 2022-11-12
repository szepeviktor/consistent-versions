<?php

declare(strict_types=1);

namespace SzepeViktor\CheckVersions;

use function register_shutdown_function;

final class Engine
{
    protected static array $errors = [];

    public static function boot()
    {
        register_shutdown_function([__CLASS__, 'displayErrors']);
    }

    public static function addError(string $errorMessage)
    {
        self::$errors[] = $errorMessage;
    }

    public static function displayErrors()
    {
        if (self::$errors !== []) {
            echo implode("\n", self::$errors), "\n";
            exit(10);
        }

        echo 'OK.', "\n";
        exit(0);
    }
}
