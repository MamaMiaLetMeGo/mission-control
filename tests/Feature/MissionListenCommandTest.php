<?php

namespace Tests\Feature;

use App\Console\Commands\MissionListenCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MissionListenCommandTest extends TestCase
{
    public function test_child_command_is_the_queue_listener_with_mission_options(): void
    {
        $this->assertSame([
            PHP_BINARY,
            'artisan',
            'queue:listen',
            '--queue=missions,default',
            '--tries=1',
            '--timeout=900',
            '--sleep=2',
            '--no-interaction',
        ], MissionListenCommand::childCommand());
    }

    public function test_the_scheduler_is_supervised_alongside_four_queue_listeners(): void
    {
        $commands = MissionListenCommand::childCommands(4);

        $this->assertCount(5, $commands);
        $this->assertSame(MissionListenCommand::childCommand(), $commands[0]);
        $this->assertSame(MissionListenCommand::childCommand(), $commands[3]);
        $this->assertSame([PHP_BINARY, 'artisan', 'schedule:work', '--no-interaction'], $commands[4]);
        $this->assertCount(2, MissionListenCommand::childCommands(1));
    }

    #[DataProvider('invalidProcessCounts')]
    public function test_process_count_outside_one_to_four_fails(string $count): void
    {
        $this->artisan('mission:listen', ['--processes' => $count])
            ->expectsOutputToContain('between 1 and 4')
            ->assertExitCode(1);
    }

    public function test_stopping_terminates_children_and_kills_one_that_ignores_the_request_after_the_grace_period(): void
    {
        $polite = new Process([PHP_BINARY, '-r', 'sleep(30);']);
        $stubborn = new Process([PHP_BINARY, '-r', 'pcntl_async_signals(true); pcntl_signal(15, SIG_IGN); echo "ready\n"; sleep(30);']);
        $polite->start();
        $stubborn->start();
        try {
            $stubborn->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready'));
            $startedAt = microtime(true);

            (new ReflectionMethod(MissionListenCommand::class, 'stopChildren'))->invoke(new MissionListenCommand, [$polite, $stubborn]);

            $elapsed = microtime(true) - $startedAt;
            $this->assertFalse($polite->isRunning());
            $this->assertFalse($stubborn->isRunning());
            $this->assertGreaterThanOrEqual(3, $elapsed);
            $this->assertLessThan(6, $elapsed);
        } finally {
            $polite->stop(0);
            $stubborn->stop(0);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProcessCounts(): array
    {
        return ['zero' => ['0'], 'five' => ['5'], 'text' => ['many']];
    }
}
