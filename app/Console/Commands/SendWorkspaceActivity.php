<?php

namespace App\Console\Commands;

use App\Models\SystemEvent;
use App\Models\Workspace;
use App\Services\SystemEventService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('system-events:activity {day? : YYYY-MM-DD, default yesterday}')]
#[Description('Send workspace.activity for each workspace with an issue change or audit row that day (once per workspace and day)')]
class SendWorkspaceActivity extends Command
{
    public function handle(SystemEventService $events): int
    {
        $day = ($this->argument('day') ? Carbon::parse($this->argument('day')) : now()->subDay())->startOfDay();
        // One run per day at a time, so two runs can't both pass the "already sent" check below.
        $lock = Cache::lock('pm:system-events:activity:'.$day->toDateString(), 600);
        if (! $lock->get()) {
            $this->error("A workspace.activity run for {$day->toDateString()} is already running.");

            return self::FAILURE;
        }
        try {
            return $this->send($events, $day);
        } finally {
            $lock->release();
        }
    }

    private function send(SystemEventService $events, Carbon $day): int
    {
        $range = [$day, $day->copy()->endOfDay()];
        $sent = 0;

        // shortcut: one query per source, then one event each; chunk it when thousands of workspaces are active in a day.
        $active = DB::table('activities')->join('issues', 'issues.id', '=', 'activities.issue_id')->join('projects', 'projects.id', '=', 'issues.project_id')
            ->whereBetween('activities.created_at', $range)->select('projects.workspace_id')
            ->union(DB::table('audit_logs')->whereNotNull('workspace_id')->whereBetween('created_at', $range)->select('workspace_id'))
            ->pluck('workspace_id');
        $done = SystemEvent::where('type', 'workspace.activity')->where('payload->day', $day->toDateString())->pluck('workspace_id');

        foreach (Workspace::whereIn('id', $active->diff($done))->get() as $workspace) {
            $sent += (int) (bool) $events->record('workspace.activity', $workspace, ['day' => $day->toDateString()]);
        }
        $this->info("Sent {$sent} workspace.activity events for {$day->toDateString()}.");

        return self::SUCCESS;
    }
}
