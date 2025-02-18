#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;

print "Press Ctrl+C to exit..." . PHP_EOL;

$suspension = EventLoop::createSuspension();

EventLoop::onSignal(\SIGINT, function (string $watcherId) use ($suspension) {
    EventLoop::cancel($watcherId);

    print "Caught SIGINT, exiting..." . PHP_EOL;

    $suspension->resume(null);

    return new \stdClass();
});

$suspension->suspend();
