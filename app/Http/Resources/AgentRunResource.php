<?php

namespace App\Http\Resources;

use App\Models\AgentRun;
use App\Models\AgentRunChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Step B: one run for its starter's run panel (B-D6: only they see it). Needs agent and changes loaded. Carries the
 * instruction, agent and role so "Propose again" can start the same run; never the AI key or the endpoint.
 *
 * @mixin AgentRun
 */
class AgentRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'role_id' => $this->role_id,
            'role_name' => $this->role_name,
            'instruction' => $this->instruction,
            'agent_id' => $this->agent_id,
            'agent' => $this->agent?->label(),
            'steps' => $this->steps,
            'tokens_in' => $this->tokens_in,
            'tokens_out' => $this->tokens_out,
            'error' => $this->error,
            'changes' => $this->changes->map(fn (AgentRunChange $c) => [
                'id' => $c->id, 'kind' => $c->kind, 'target' => $c->target, 'before' => $c->before, 'after' => $c->after, 'status' => $c->status->value, 'message' => $c->message,
            ])->values()->all(),
        ];
    }
}
