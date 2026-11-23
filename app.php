<?php
declare(strict_types=1);

use MyPHP\{App, Device, Window, WindowOptions};
use MyPHP\UI\Content;
use MyQuant\QuantState;

require __DIR__ . '/boot.php';

App::setName('MyQuant');
$state = new QuantState();
if (($initTab = getenv('MYQUANT_TAB')) && is_string($initTab)) {
    $state->tab = $initTab;
}

App::whenReady(function () use ($state): void {
    [$w, $h] = Device::isDesktop() ? [1180, 780] : [402, 874];
    $state->window = new Window(new WindowOptions(
        title: 'MyQuant — 跨市场量化研究工作台',
        width: $w,
        height: $h,
        stateKey: 'main',
        content: Content::file(__DIR__ . '/view.php', $state),
    ));
});

App::run();
