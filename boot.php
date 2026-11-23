<?php
// Loads MyPHP from ./myphp inside a packaged app, $MYPHP_HOME in dev/screenshot, or Composer's vendor/owner888/myphp.
declare(strict_types=1);

if (!class_exists(\MyPHP\App::class)) {
    $configured = is_file(__DIR__ . '/myphp.json') ? (json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] ?? null) : null;
    if (is_string($configured) && $configured !== '' && !str_starts_with($configured, '/')) {
        $configured = __DIR__ . '/' . $configured;
    }
    $framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp'
        : (getenv('MYPHP_HOME') ?: ($configured ?: __DIR__ . '/vendor/owner888/myphp'));
    if (is_file($framework . '/src/autoload.php')) {
        require_once $framework . '/src/autoload.php';
    }
}
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
