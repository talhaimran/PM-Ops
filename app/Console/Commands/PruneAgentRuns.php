<?php

namespace App\Console\Commands;

use App\Services\AgentRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('agent-runs:prune')]
#[Description('Drop the detail of agent runs older than 30 days, keeping who, when, the issue and tokens')]
class PruneAgentRuns extends Command
{
    public function handle(AgentRunService $runs): int
    {
        $count = $runs->prune();
        $this->info("Pruned {$count} agent ".Str::plural('run', $count).'.');

        return self::SUCCESS;
    }
}
