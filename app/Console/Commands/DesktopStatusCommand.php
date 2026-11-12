<?php

namespace App\Console\Commands;

use App\Services\DesktopStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('mission:desktop-status {--json : Return the machine-readable desktop status (the default)}')]
#[Description('Read desktop identity, dependencies, databases, and active work without starting processes')]
class DesktopStatusCommand extends Command
{
    public function handle(DesktopStatus $status): int
    {
        try {
            $result = $status->status();
        } catch (Throwable) {
            $result = ['application' => DesktopStatus::APPLICATION, 'ready' => false, 'message' => 'Desktop readiness could not be verified. Check the local installation.'];
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
