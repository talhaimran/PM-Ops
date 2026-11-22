<?php

namespace App\Services;

use App\Enums\AiProviderType;
use App\Models\AiProvider;
use App\Models\Project;
use App\Models\ProjectAgentSettings;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Spec 8 (A5): the workspace's AI connection, from the server's environment (PM_AI_*) or Settings → AI. key() is the
 * only place the key is read; nothing here returns it, logs it or puts it in an audit row.
 */
class AiSettingsService
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array{provider: AiProviderType, model: string, endpoint: ?string, source: 'env'|'workspace'}|null  No key. */
    public function for(Workspace $workspace): ?array
    {
        if ($env = $this->env()) {
            return $env;
        }
        $row = $this->row($workspace);

        // A row with no provider only holds the cap and usage.
        return $row?->provider ? ['provider' => $row->provider, 'model' => $row->model, 'endpoint' => $row->endpoint, 'source' => 'workspace'] : null;
    }

    /** The only way to read the key (for the provider calls of Mode C, search and release notes). */
    public function key(Workspace $workspace): ?string
    {
        return $this->env() ? (config('pm.ai.key') ?: null) : $this->row($workspace)?->key;
    }

    public function hasKey(Workspace $workspace): bool
    {
        return $this->env()
            ? filled(config('pm.ai.key'))
            : AiProvider::where('workspace_id', $workspace->id)->whereNotNull('key')->exists();
    }

    /** @return array{cap: ?int, used: int} Kept in the workspace's row whether the provider is the server's or its own. */
    public function usage(Workspace $workspace): array
    {
        $row = $this->row($workspace);

        return ['cap' => $row?->monthly_token_cap, 'used' => $row?->tokens_used_month ?? 0];
    }

    /** The key belongs to one provider and endpoint: moving either means the old key must not go along. */
    public function keyMoves(Workspace $workspace, AiProviderType $provider, ?string $endpoint): bool
    {
        $current = $this->for($workspace);

        return $current !== null && $this->hasKey($workspace) && ($current['provider'] !== $provider || $current['endpoint'] !== (filled($endpoint) ? $endpoint : null));
    }

    /**
     * @param  array{provider: string, model: string, endpoint?: ?string, api_key?: ?string}  $data  A blank api_key keeps the stored one,
     *                                                                                               unless the provider or endpoint changed, which drops it.
     */
    public function update(Workspace $workspace, User $actor, #[\SensitiveParameter] array $data): void
    {
        $this->refuseWhenSetByServer('provider');
        $provider = AiProviderType::from($data['provider']);
        $endpoint = filled($data['endpoint'] ?? null) ? $data['endpoint'] : null;
        $newKey = filled($data['api_key'] ?? null) ? $data['api_key'] : null;

        // shortcut: two first saves in the same instant can hit the unique workspace_id and one gets a 500; add a lock if that shows up.
        DB::transaction(function () use ($workspace, $actor, $data, $provider, $endpoint, $newKey) {
            $row = AiProvider::where('workspace_id', $workspace->id)->lockForUpdate()->first() ?? new AiProvider(['workspace_id' => $workspace->id]);
            $dropKey = ! $newKey && $this->keyMoves($workspace, $provider, $endpoint);
            $cipher = $newKey ? $this->encrypted($newKey) : null;

            $row->fill(['provider' => $provider, 'model' => $data['model'], 'endpoint' => $endpoint]);
            $changed = $row->isDirty(['provider', 'model', 'endpoint']);
            if ($cipher) {
                // Already encrypted: setting the attribute would put the plaintext through the model's unmarked frames.
                $row->setRawAttributes([...$row->getAttributes(), 'key' => $cipher]);
            } elseif ($dropKey) {
                $row->key = null;
            }
            $row->save();

            if ($changed) {
                $this->audit->log('ai_provider.updated', $workspace, ['provider' => $provider->value, 'model' => $data['model'], 'endpoint' => $endpoint], $workspace, $actor);
            }
            if ($newKey || $dropKey) {
                $this->audit->log('ai_provider.key_changed', $workspace, [], $workspace, $actor);
            }
        });
    }

    public function remove(Workspace $workspace, User $actor): void
    {
        $this->refuseWhenSetByServer('provider');
        DB::transaction(function () use ($workspace, $actor) {
            $row = AiProvider::where('workspace_id', $workspace->id)->lockForUpdate()->first();
            if ($row?->provider) {
                $row->delete();
                $this->audit->log('ai_provider.removed', $workspace, ['provider' => $row->provider->value, 'model' => $row->model], $workspace, $actor);
            }
        });
    }

    public function setCap(Workspace $workspace, User $actor, ?int $cap): void
    {
        // The cap is editable either way (A-L8), so a server-set provider gets a row that holds only the cap.
        $this->for($workspace) ?? throw ValidationException::withMessages(['monthly_token_cap' => 'Set up an AI provider first.']);
        $row = $this->row($workspace) ?? new AiProvider(['workspace_id' => $workspace->id]);
        if ($row->monthly_token_cap === $cap) {
            return;
        }
        $before = $row->monthly_token_cap;
        $row->fill(['monthly_token_cap' => $cap])->save();
        $this->audit->log('ai_provider.cap_changed', $workspace, ['before' => $before, 'after' => $cap], $workspace, $actor);
    }

    /** Mode C runs only with a provider, the workspace switch and the project switch all on. */
    public function modeCEnabled(Project $project): bool
    {
        $workspace = $project->workspace()->first();

        return $this->for($workspace) !== null
            && (bool) $workspace->setting('mode_c_enabled')
            && (bool) (ProjectAgentSettings::where('project_id', $project->id)->value('mode_c_enabled') ?? true);
    }

    /** For display: the endpoint without any login, query or fragment; null when it can't be read. */
    public function displayEndpoint(string $endpoint): ?string
    {
        $url = parse_url($endpoint);
        if (empty($url['scheme']) || empty($url['host'])) {
            return null;
        }

        return strtolower($url['scheme']).'://'.$url['host'].(isset($url['port']) ? ":{$url['port']}" : '').($url['path'] ?? '');
    }

    /** PM_AI_PROVIDER is set but unusable (unknown provider or no PM_AI_MODEL): the page says so. */
    public function envIgnored(): bool
    {
        return filled(config('pm.ai.provider')) && $this->env() === null;
    }

    /** The plaintext key lives only in this marked frame on its way to ciphertext (stack traces print arguments). */
    private function encrypted(#[\SensitiveParameter] string $key): string
    {
        return Crypt::encryptString($key);
    }

    /** PM_AI_PROVIDER with a known provider and a model: the install's own connection, which no workspace overrides. */
    private function env(): ?array
    {
        $provider = AiProviderType::tryFrom((string) config('pm.ai.provider'));

        return $provider && filled(config('pm.ai.model'))
            ? ['provider' => $provider, 'model' => config('pm.ai.model'), 'endpoint' => config('pm.ai.endpoint') ?: null, 'source' => 'env']
            : null;
    }

    private function row(Workspace $workspace): ?AiProvider
    {
        return AiProvider::where('workspace_id', $workspace->id)->first();
    }

    private function refuseWhenSetByServer(string $field): void
    {
        if ($this->env()) {
            throw ValidationException::withMessages([$field => 'Your server sets the AI provider in its environment (PM_AI_PROVIDER). Change it there.']);
        }
    }
}
