<?php

/** @noinspection PhpComposerExtensionStubsInspection */

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\Callback;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;

final class EventDriver extends AbstractDriver
{
    /** @var \Event[]|null */
    private static ?array $activeSignals = null;

    public static function isSupported(): bool
    {
        return \extension_loaded("event");
    }

    private \EventBase $handle;
    /** @var \Event[] */
    private array $events = [];
    private \Closure $ioCallback;
    private \Closure $timerCallback;
    private \Closure $signalCallback;
    private array $signals = [];

    public function __construct()
    {
        parent::__construct();

        /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
        $this->handle = new \EventBase();

        if (self::$activeSignals === null) {
            self::$activeSignals = &$this->signals;
        }

        $this->ioCallback = function ($resource, $what, StreamCallback $callback): void {
            \assert(\is_resource($callback->stream));

            $this->invokeCallback($callback);
        };

        $this->timerCallback = function ($resource, $what, TimerCallback $callback): void {
            if ($callback->repeat) {
                $this->events[$callback->id]->add($callback->interval);
            } else {
                $this->cancel($callback->id);
            }

            $this->invokeCallback($callback);
        };

        $this->signalCallback = function ($signo, $what, SignalCallback $callback): void {
            $this->invokeCallback($callback);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function cancel(string $callbackId): void
    {
        parent::cancel($callbackId);

        if (isset($this->events[$callbackId])) {
            $this->events[$callbackId]->free();
            unset($this->events[$callbackId]);
        }
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        foreach ($this->events as $event) {
            if ($event !== null) { // Events may have been nulled in extension depending on destruct order.
                $event->free();
            }
        }

        // Unset here, otherwise $event->del() fails with a warning, because __destruct order isn't defined.
        // See https://github.com/amphp/amp/issues/159.
        $this->events = [];

        // Manually free the loop handle to fully release loop resources.
        // See https://github.com/amphp/amp/issues/177.
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (isset($this->handle)) {
            $this->handle->free();
            unset($this->handle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $active = self::$activeSignals;

        \assert($active !== null);

        foreach ($active as $event) {
            $event->del();
        }

        self::$activeSignals = &$this->signals;

        foreach ($this->signals as $event) {
            /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
            $event->add();
        }

        try {
            parent::run();
        } finally {
            foreach ($this->signals as $event) {
                $event->del();
            }

            self::$activeSignals = &$active;

            foreach ($active as $event) {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $event->add();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->handle->stop();
        parent::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function getHandle(): \EventBase
    {
        return $this->handle;
    }

    protected function now(): float
    {
        return (float) \hrtime(true) / 1_000_000_000;
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatch(bool $blocking): void
    {
        $this->handle->loop($blocking ? \EventBase::LOOP_ONCE : \EventBase::LOOP_ONCE | \EventBase::LOOP_NONBLOCK);
    }

    /**
     * {@inheritdoc}
     */
    protected function activate(array $callbacks): void
    {
        $now = $this->now();

        foreach ($callbacks as $callback) {
            if (!isset($this->events[$id = $callback->id])) {
                if ($callback instanceof StreamReadableCallback) {
                    \assert(\is_resource($callback->stream));

                    $this->events[$id] = new \Event(
                        $this->handle,
                        $callback->stream,
                        \Event::READ | \Event::PERSIST,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof StreamWritableCallback) {
                    \assert(\is_resource($callback->stream));

                    $this->events[$id] = new \Event(
                        $this->handle,
                        $callback->stream,
                        \Event::WRITE | \Event::PERSIST,
                        $this->ioCallback,
                        $callback
                    );
                } elseif ($callback instanceof TimerCallback) {
                    $this->events[$id] = new \Event(
                        $this->handle,
                        -1,
                        \Event::TIMEOUT,
                        $this->timerCallback,
                        $callback
                    );
                } elseif ($callback instanceof SignalCallback) {
                    $this->events[$id] = new \Event(
                        $this->handle,
                        $callback->signal,
                        \Event::SIGNAL | \Event::PERSIST,
                        $this->signalCallback,
                        $callback
                    );
                } else {
                    // @codeCoverageIgnoreStart
                    throw new \Error("Unknown callback type");
                    // @codeCoverageIgnoreEnd
                }
            }

            if ($callback instanceof TimerCallback) {
                $interval = \max(0, $callback->expiration - $now);
                $this->events[$id]->add($interval > 0 ? $interval : 0);
            } elseif ($callback instanceof SignalCallback) {
                $this->signals[$id] = $this->events[$id];
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            } else {
                /** @psalm-suppress TooFewArguments https://github.com/JetBrains/phpstorm-stubs/pull/763 */
                $this->events[$id]->add();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function deactivate(Callback $callback): void
    {
        if (isset($this->events[$id = $callback->id])) {
            $this->events[$id]->del();

            if ($callback instanceof SignalCallback) {
                unset($this->signals[$id]);
            }
        }
    }
}
