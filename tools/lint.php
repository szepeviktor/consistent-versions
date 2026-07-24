<?php

declare(strict_types=1);

$roots = [__DIR__ . '/../src', __DIR__ . '/../tests'];
$failed = false;

foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $failed = true;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }
        $output = [];
    }
}

exit($failed ? 1 : 0);
