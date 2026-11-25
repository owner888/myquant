<?php
declare(strict_types=1);

use MyPHP\{App, Device, Window, WindowOptions};
use MyPHP\UI\Content;
use MyQuant\QuantState;

require __DIR__ . '/boot.php';

App::setName('MyQuant');
$state = new QuantState();
App::onOpenFile(static fn(string $path) => $state->importLocalFile($path));
if (($initScale = getenv('MYQUANT_SCALE')) && is_string($initScale)) {
    $state->selectScaleMode($initScale);
}
if (($initDir = getenv('MYQUANT_DIR')) && is_string($initDir)) {
    $state->selectDirection($initDir);
}
if (($initTab = getenv('MYQUANT_TAB')) && is_string($initTab)) {
    $state->tab = $initTab;
}

App::whenReady(function () use ($state): void {
    [$w, $h] = Device::isDesktop() ? [1180, 780] : (Device::isPad() ? [1100, 820] : [402, 874]);
    $desktop = Device::isDesktop();
    $macDesktop = PHP_OS_FAMILY === 'Darwin' && $desktop;
    $state->window = new Window(new WindowOptions(
        title: 'MyQuant',
        width: $w,
        height: $h,
        minWidth: $desktop ? 920 : 320,
        minHeight: $desktop ? 620 : 480,
        titleBarStyle: $macDesktop ? 'hiddenInset' : 'default',
        vibrancy: $desktop ? 'sidebar' : '', // macOS NSVisualEffect sidebar + Windows 11 Mica sidebar
        stateKey: 'main',
        content: Content::file(__DIR__ . '/view.php', $state),
    ));
});

App::run();
