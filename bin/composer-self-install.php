#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Composer hook: best-effort installer binary bootstrap.
 *
 * Runs `bin/evo self-install` when the installer itself is installed/updated,
 * but never blocks Composer if the bootstrap fails (e.g. no network in CI).
 *
 * Set `EVO_SELF_INSTALL_STRICT=1` to make failures fatal.
 */

$strict = getenv('EVO_SELF_INSTALL_STRICT');
$strict = is_string($strict) && ($strict === '1' || strtolower($strict) === 'true');

$php = PHP_BINARY ?: 'php';
$evo = __DIR__ . DIRECTORY_SEPARATOR . 'evo';

$cmd = escapeshellarg($php) . ' ' . escapeshellarg($evo) . ' self-install';
passthru($cmd, $code);
$code = (int) $code;

if ($code !== 0 && !$strict) {
    fwrite(STDERR, "Warning: installer self-install failed (exit {$code}). Continuing.\n");
    exit(0);
}

exit($code);

