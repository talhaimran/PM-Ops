<?php

namespace App\Enums;

/** Step B (B-R16): where an in-app agent run stands. */
enum AgentRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Finished = 'finished';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
