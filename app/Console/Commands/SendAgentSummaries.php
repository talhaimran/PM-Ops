<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('notifications:agent-summary {day? : YYYY-MM-DD, default yesterday}')]
#[Description('Tell each person what their agents did the day before (once per person and day)')]
class SendAgentSummaries extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $day = $this->argument('day') ? Carbon::parse($this->argument('day')) : now()->subDay();
        $sent = 0;
        // shortcut: one query for the pairs, then one summary each; chunk or queue it when thousands of owners have agents.
        $pairs = DB::table('activities')->join('agents', 'agents.id', '=', 'activities.agent_id')
            ->whereBetween('activities.created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->distinct()->get(['agents.workspace_id', 'agents.owner_user_id']);
        foreach ($pairs as $pair) {
            $workspace = Workspace::find($pair->workspace_id);
            $owner = User::find($pair->owner_user_id);
            if ($workspace && $owner && $notifications->summary($workspace, $owner, $day)) {
                $sent++;
            }
        }
        $this->info("Sent {$sent} agent summaries for {$day->toDateString()}.");

        return self::SUCCESS;
    }
}
