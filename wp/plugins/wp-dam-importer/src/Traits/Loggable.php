<?php

declare(strict_types=1);

namespace MariusCucuruz\DAMImporter\Traits;

use Illuminate\Support\Traits\Dumpable;
use Symfony\Component\Console\Output\ConsoleOutput;

trait Loggable
{
    use Dumpable;

    public function log(string $text, string $level = 'info', ?string $icon = null, array $context = []): string
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $backtrace[0];
        $file = $caller['file'] ?? null;
        $line = $caller['line'] ?? null;
        $model = $caller['function'] && $caller['type'] ? class_basename($this) . $caller['type'] . $caller['function'] : class_basename($this);
        $now = now()->format('j F Y @ H:i:s');
        $text = mb_convert_encoding($text, 'UTF-8');

        if ($file && $line) {
            $context['trace'] = $file . ':' . $line;
        }

        $level = strtolower($level);

        if (in_array($level, ['warn', 'warning']) && empty($icon)) {
            $icon = '⚠️';
            $level = 'warn';
        }

        if (in_array($level, ['error', 'danger']) && empty($icon)) {
            $icon = '❌';
            $level = 'error';
        }

        if ($level === 'info' && empty($icon)) {
            $icon = 'ℹ️';
        }

        $log = "{$now} | {$model}  {$icon}  {$text}";

        if (app()->runningInConsole()) {
            if (in_array($level, ['info', 'warn', 'error']) && method_exists($this, $level)) {
                // @phpstan-ignore-next-line
                $this->{$level}($log);
            } else {
                (new ConsoleOutput)->writeln($log);
            }
        }

        if ($level === 'warn') {
            $level = 'warning';
        }

        logger()->{$level}($log, $context);

        return $log;
    }

    public function startLog(): string
    {
        $startLog = 'START';

        $this->concludedLog($startLog, '🏁', '-', 6);

        return $startLog;
    }

    public function endLog(): string
    {
        $endLog = 'END';

        $this->concludedLog($endLog, '🏁', '-', 6);

        return $endLog;
    }

    public function concludedLog(?string $text, string $icon = '✅', string $borderChar = '=', int $borderSize = 9): string
    {
        $border = str_repeat($borderChar, $borderSize);
        $border = mb_substr($border, 0, $borderSize);

        $log = "{$border} {$text} {$border}";
        $this->log(text: $log, icon: $icon);

        return $log;
    }
}
