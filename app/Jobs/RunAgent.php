<?php

namespace App\Jobs;

use App\Services\AgentRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** B-R2: works one in-app agent run, once. Carries only the run id; the loop lives in AgentRunService::run(). */
class RunAgent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /** A worker killed at the timeout still marks the run failed (failed() below). */
    public bool $failOnTimeout = true;

    /** $timeoutSeconds: the workspace's run_timeout_seconds; the worker gets 30 s more, so the loop's own limit stops it first. */
    public function __construct(public int $runId, int $timeoutSeconds = 180)
    {
        $this->timeout = $timeoutSeconds + 30;
    }

    public function handle(AgentRunService $runs): void
    {
        $runs->run($this->runId);
    }

    public function failed(?Throwable $e): void
    {
        app(AgentRunService::class)->crashed($this->runId);
    }
}
