<?php
// Loads MyPHP as a Flutter project finds its SDK: the one installed on the machine, which the myphp command
// (dev, run, test, screenshot, build) names in $MYPHP_HOME; inside a built app, the copy bundled in ./myphp;
// or the checkout myphp.json's "framework" points at. MyPHP is not a Composer dependency of the project:
// `myphp upgrade` updates it for every project at once, and myphp.json's "myphp" says the least version needed.
// Composer's autoloader, when the project has other packages, is loaded first.
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'MyQuant\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
if (!class_exists(\MyPHP\App::class)) {
    $configured = is_file(__DIR__ . '/myphp.json') ? (json_decode((string)file_get_contents(__DIR__ . '/myphp.json'), true)['framework'] ?? null) : null;
    if (is_string($configured) && $configured !== '' && !str_starts_with($configured, '/')) {
        $configured = __DIR__ . '/' . $configured;
    }
    $framework = is_dir(__DIR__ . '/myphp') ? __DIR__ . '/myphp' : (getenv('MYPHP_HOME') ?: ($configured ?: null));
    if ($framework === null || !is_file($framework . '/src/autoload.php')) {
        throw new RuntimeException('MyPHP was not found. Run the app and its tests through the myphp command (myphp dev, myphp run, myphp test), or set MYPHP_HOME to a MyPHP checkout. Install: https://github.com/owner888/myphp');
    }
    require_once $framework . '/src/autoload.php';
}

// 默认使用 MyPHP 自带的纯 PHP 网络栈（stream_select + Fiber，HTTP/2），不依赖系统事件通知（MYPHP_HTTP=curl 可切回 libcurl）。
if (getenv('MYPHP_HTTP') !== 'curl') {
    \MyPHP\Http::useTransport('php');
}
