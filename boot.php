<?php
// Loads Composer's autoloader (or built-in MyQuant PSR-4 fallback) and prepends $MYPHP_HOME / ./myphp when running via global `myphp` CLI or built bundle.
declare(strict_types=1);

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

$configured = is_file(__DIR__ . '/myphp.json') ? (json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] ?? null) : null;
if (is_string($configured) && $configured !== '' && !str_starts_with($configured, '/')) {
    $configured = __DIR__ . '/' . $configured;
}
$framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
    : (getenv('MYPHP_HOME') ?: ($configured ?: (!class_exists(\MyPHP\App::class) ? __DIR__ . '/vendor/owner888/myphp' : null)));
if ($framework !== null && is_file($framework . '/src/autoload.php')) {
    require_once $framework . '/src/autoload.php';
}
