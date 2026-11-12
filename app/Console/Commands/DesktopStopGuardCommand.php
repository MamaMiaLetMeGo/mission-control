<?php

namespace App\Console\Commands;

use App\Services\DesktopStopGuard;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

#[Signature('mission:desktop-stop-guard')]
#[Description('Hold a bounded shutdown guard while the desktop launcher stops its own idle processes')]
class DesktopStopGuardCommand extends Command
{
    public function handle(DesktopStopGuard $guard): int
    {
        $announced = false;
        $announce = function (array $result) use (&$announced): void {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $announced = true;
            fflush(STDOUT);
        };
        try {
            $input = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
            $released = $guard->hold($input ?? STDIN, $announce);
        } catch (Throwable) {
            $released = false;
        }
        if (! $announced) {
            $announce(['ready' => false, 'message' => 'The shutdown guard could not verify safe shutdown. Mission Control will remain running.']);
        }

        return $released ? self::SUCCESS : self::FAILURE;
    }
}
