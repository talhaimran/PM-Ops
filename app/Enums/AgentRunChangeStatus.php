<?php

namespace App\Enums;

/** Step B (B-R18): a change a run proposed, and what became of it. */
enum AgentRunChangeStatus: string
{
    case Proposed = 'proposed';
    case Applied = 'applied';
    case Discarded = 'discarded';
    case Conflict = 'conflict';
}
