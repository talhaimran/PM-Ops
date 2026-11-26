<?php

namespace App\Console\Commands;

use App\Services\SupportSessionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('support-sessions:end')]
#[Description('End support sessions past their end time and log staff.support_session_ended (3-R19)')]
class EndSupportSessions extends Command
{
    public function handle(SupportSessionService $support): int
    {
        $count = $support->endDue();
        $this->info("Ended {$count} support ".Str::plural('session', $count).'.');

        return self::SUCCESS;
    }
}
