<?php
// Loads $MYPHP_HOME / ./myphp FIRST when present (so `myphp dev` & built bundles always use their own framework),
// then Composer's autoloader (or built-in MyQuant PSR-4 fallback).
declare(strict_types=1);

$configured = is_file(__DIR__ . '/myphp.json') ? (json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] ?? null) : null;
if (is_string($configured) && $configured !== '' && !str_starts_with($configured, '/')) {
    $configured = __DIR__ . '/' . $configured;
}
$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
    : (getenv('MYPHP_HOME') ?: ($configured ?: null));
if ($framework !== null && is_file($framework . '/src/autoload.php')) {
    require_once $framework . '/src/autoload.php';
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'MyQuant\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
