<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

#[Signature('mission:listen {--processes=4 : Number of queue listeners to run (1-4)}')]
#[Description('Run several queue listeners and stop them all together')]
class MissionListenCommand extends Command
{
    private const MAX_PROCESSES = 4;

    private const RESTART_PAUSE_SECONDS = 1;

    private const STOP_GRACE_SECONDS = 3;

    /**
     * SIGTERM by number: the named constant exists only when the pcntl extension is loaded.
     */
    private const TERMINATE_SIGNAL = 15;

    private bool $stopRequested = false;

    /**
     * @return list<string>
     */
    public static function childCommand(): array
    {
        return [
            PHP_BINARY,
            'artisan',
            'queue:listen',
            '--queue=missions,default',
            '--tries=1',
            '--timeout=900',
            '--sleep=2',
            '--no-interaction',
        ];
    }

    public function handle(): int
    {
        $requested = $this->option('processes');
        $count = filter_var($requested, FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > self::MAX_PROCESSES) {
            $this->error('The --processes option must be a whole number between 1 and '.self::MAX_PROCESSES.'.');

            return self::FAILURE;
        }

        $this->registerSignalHandlers();

        /** @var array<int, Process> $children */
        $children = [];
        /** @var array<int, float> $restartAt */
        $restartAt = [];

        try {
            for ($slot = 0; $slot < $count; $slot++) {
                $children[$slot] = $this->startChild();
            }

            while (! $this->stopRequested) {
                foreach ($children as $slot => $child) {
                    if ($child->isRunning()) {
                        continue;
                    }
                    $restartAt[$slot] ??= microtime(true) + self::RESTART_PAUSE_SECONDS;
                    if (microtime(true) >= $restartAt[$slot]) {
                        unset($restartAt[$slot]);
                        $children[$slot] = $this->startChild();
                    }
                }
                usleep(200_000);
            }
        } finally {
            $this->stopChildren($children);
        }

        return self::SUCCESS;
    }

    private function startChild(): Process
    {
        $child = new Process(self::childCommand(), base_path(), null, null, null);
        $child->start(function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                fwrite(STDERR, $buffer);
            } else {
                fwrite(STDOUT, $buffer);
            }
        });

        return $child;
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->stopRequested = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    /**
     * @param  array<int, Process>  $children
     */
    private function stopChildren(array $children): void
    {
        foreach ($children as $child) {
            if ($child->isRunning()) {
                try {
                    $child->signal(self::TERMINATE_SIGNAL);
                } catch (ProcessException) {
                    // The child exited between the check and the signal.
                }
            }
        }
        $deadline = microtime(true) + self::STOP_GRACE_SECONDS;
        while (microtime(true) < $deadline && array_filter($children, fn (Process $child): bool => $child->isRunning()) !== []) {
            usleep(100_000);
        }
        foreach ($children as $child) {
            $child->stop(0);
        }
    }
}
