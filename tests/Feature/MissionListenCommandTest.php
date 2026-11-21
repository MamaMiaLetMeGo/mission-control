<?php

namespace Tests\Feature;

use App\Console\Commands\MissionListenCommand;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('invalidProcessCounts')]
    public function test_process_count_outside_one_to_four_fails(string $count): void
    {
        $this->artisan('mission:listen', ['--processes' => $count])
            ->expectsOutputToContain('between 1 and 4')
            ->assertExitCode(1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidProcessCounts(): array
    {
        return ['zero' => ['0'], 'five' => ['5'], 'text' => ['many']];
    }
}
