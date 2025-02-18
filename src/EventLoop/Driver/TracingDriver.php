<?php

namespace Revolt\EventLoop\Driver;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;

final class TracingDriver implements Driver
{
    private Driver $driver;

    /** @var true[] */
    private array $enabledCallbacks = [];

    /** @var true[] */
    private array $unreferencedCallbacks = [];

    /** @var string[] */
    private array $creationTraces = [];

    /** @var string[] */
    private array $cancelTraces = [];

    public function __construct(Driver $driver)
    {
        $this->driver = $driver;
    }

    public function run(): void
    {
        $this->driver->run();
    }

    public function stop(): void
    {
        $this->driver->stop();
    }

    public function createSuspension(\Fiber $scheduler): Suspension
    {
        return $this->driver->createSuspension($scheduler);
    }

    public function isRunning(): bool
    {
        return $this->driver->isRunning();
    }

    public function defer(callable $callback): string
    {
        $id = $this->driver->defer(function (...$args) use ($callback) {
            $this->cancel($args[0]);
            return $callback(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function delay(float $delay, callable $callback): string
    {
        $id = $this->driver->delay($delay, function (...$args) use ($callback) {
            $this->cancel($args[0]);
            return $callback(...$args);
        });

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function repeat(float $interval, callable $callback): string
    {
        $id = $this->driver->repeat($interval, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function onReadable(mixed $stream, callable $callback): string
    {
        $id = $this->driver->onReadable($stream, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function onWritable(mixed $stream, callable $callback): string
    {
        $id = $this->driver->onWritable($stream, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function onSignal(int $signo, callable $callback): string
    {
        $id = $this->driver->onSignal($signo, $callback);

        $this->creationTraces[$id] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        $this->enabledCallbacks[$id] = true;

        return $id;
    }

    public function enable(string $callbackId): string
    {
        try {
            $this->driver->enable($callbackId);
            $this->enabledCallbacks[$callbackId] = true;
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    public function cancel(string $callbackId): void
    {
        $this->driver->cancel($callbackId);

        if (!isset($this->cancelTraces[$callbackId])) {
            $this->cancelTraces[$callbackId] = $this->formatStacktrace(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        unset($this->enabledCallbacks[$callbackId], $this->unreferencedCallbacks[$callbackId]);
    }

    public function disable(string $callbackId): string
    {
        $this->driver->disable($callbackId);
        unset($this->enabledCallbacks[$callbackId]);

        return $callbackId;
    }

    public function reference(string $callbackId): string
    {
        try {
            $this->driver->reference($callbackId);
            unset($this->unreferencedCallbacks[$callbackId]);
        } catch (InvalidCallbackError $e) {
            $e->addInfo("Creation trace", $this->getCreationTrace($callbackId));
            $e->addInfo("Cancellation trace", $this->getCancelTrace($callbackId));

            throw $e;
        }

        return $callbackId;
    }

    public function unreference(string $callbackId): string
    {
        $this->driver->unreference($callbackId);
        $this->unreferencedCallbacks[$callbackId] = true;

        return $callbackId;
    }

    public function setErrorHandler(callable $callback = null): ?callable
    {
        return $this->driver->setErrorHandler($callback);
    }

    /** @inheritdoc */
    public function getHandle(): mixed
    {
        return $this->driver->getHandle();
    }

    public function dump(): string
    {
        $dump = "Enabled, referenced callbacks keeping the loop running: ";

        foreach ($this->enabledCallbacks as $callbackId => $_) {
            if (isset($this->unreferencedCallbacks[$callbackId])) {
                continue;
            }

            $dump .= "Callback identifier: " . $callbackId . "\r\n";
            $dump .= $this->getCreationTrace($callbackId);
            $dump .= "\r\n\r\n";
        }

        return \rtrim($dump);
    }

    public function getInfo(): array
    {
        return $this->driver->getInfo();
    }

    public function __debugInfo(): array
    {
        return $this->driver->__debugInfo();
    }

    public function queue(callable $callback, mixed ...$args): void
    {
        $this->driver->queue($callback, ...$args);
    }

    private function getCreationTrace(string $callbackId): string
    {
        return $this->creationTraces[$callbackId] ?? 'No creation trace, yet.';
    }

    private function getCancelTrace(string $callbackId): string
    {
        return $this->cancelTraces[$callbackId] ?? 'No cancellation trace, yet.';
    }

    /**
     * Formats a stacktrace obtained via `debug_backtrace()`.
     *
     * @param array<array{file?: string, line: int, type?: string, class?: class-string, function: string}> $trace
     *     Output of `debug_backtrace()`.
     *
     * @return string Formatted stacktrace.
     */
    private function formatStacktrace(array $trace): string
    {
        return \implode("\n", \array_map(static function ($e, $i) {
            $line = "#{$i} ";

            if (isset($e["file"])) {
                $line .= "{$e['file']}:{$e['line']} ";
            }

            if (isset($e["class"], $e["type"])) {
                $line .= $e["class"] . $e["type"];
            }

            return $line . $e["function"] . "()";
        }, $trace, \array_keys($trace)));
    }
}
