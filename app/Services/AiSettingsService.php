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

    /** The usable connection, or null. A connection is usable when it has what its provider needs (see problems()). No key in the result. @return array{provider: AiProviderType, model: string, endpoint: ?string, source: 'env'|'workspace'}|null */
    public function for(Workspace $workspace): ?array
    {
        if ($env = $this->env()) {
            return $env;
        }
        $stored = $this->stored($workspace);

        return $stored && ! $this->problems($stored, $this->hasKey($workspace)) ? $stored : null;
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
        $current = $this->env() ?? $this->stored($workspace);

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
            $endpointChanged = $row->getRawOriginal('endpoint') !== $endpoint;
            if ($cipher) {
                // Already encrypted: setting the attribute would put the plaintext through the model's unmarked frames.
                $row->setRawAttributes([...$row->getAttributes(), 'key' => $cipher]);
            } elseif ($dropKey) {
                $row->key = null;
            }
            $row->save();

            if ($changed) {
                $this->audit->log('ai_provider.updated', $workspace, [
                    'provider' => $provider->value, 'model' => $data['model'],
                    'endpoint' => $endpoint ? $this->displayEndpoint($endpoint) : null, 'endpoint_changed' => $endpointChanged,
                ], $workspace, $actor);
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
                $removed = ['provider' => $row->provider->value, 'model' => $row->model];
                // The row stays: the cap and this month's usage belong to the workspace, not to the connection.
                $row->forceFill(['provider' => null, 'model' => null, 'endpoint' => null, 'key' => null])->save();
                $this->audit->log('ai_provider.removed', $workspace, $removed, $workspace, $actor);
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

    /** For display and the audit log: only scheme://host[:port], since the path or query may hold a token; null when it can't be read. */
    public function displayEndpoint(string $endpoint): ?string
    {
        $url = parse_url($endpoint);
        if (empty($url['scheme']) || empty($url['host'])) {
            return null;
        }

        return strtolower($url['scheme']).'://'.$url['host'].(isset($url['port']) ? ":{$url['port']}" : '');
    }

    /** Why PM_AI_PROVIDER is being ignored, naming variables only and never their values; null when it is unset or in use. */
    public function envWarning(): ?string
    {
        if (blank(config('pm.ai.provider'))) {
            return null;
        }
        $connection = $this->envConnection();
        if (! $connection) {
            return 'PM_AI_PROVIDER is set on your server but isn’t used: it must be one of anthropic, openai, google, openai_compatible, with PM_AI_MODEL set.';
        }
        $problems = $this->problems($connection, filled(config('pm.ai.key')));
        $names = ['key_missing' => 'PM_AI_KEY is missing', 'endpoint_missing' => 'PM_AI_ENDPOINT is missing',
            'endpoint_not_allowed' => 'PM_AI_ENDPOINT is not allowed (it must be a public https:// address with no login or query)'];

        return $problems ? 'PM_AI_PROVIDER is set on your server but the connection '.(array_intersect($problems, ['key_missing', 'endpoint_missing']) ? 'is incomplete' : 'can’t be used').': '
            .implode('; ', array_map(fn ($p) => $names[$p], $problems)).'.' : null;
    }

    /** The plaintext key lives only in this marked frame on its way to ciphertext (stack traces print arguments). */
    private function encrypted(#[\SensitiveParameter] string $key): string
    {
        return Crypt::encryptString($key);
    }

    /** The server's own connection (PM_AI_*), when it is usable: no workspace overrides it. Otherwise ignored (see envWarning()). */
    private function env(): ?array
    {
        $connection = $this->envConnection();

        return $connection && ! $this->problems($connection, filled(config('pm.ai.key'))) ? $connection : null;
    }

    private function envConnection(): ?array
    {
        $provider = AiProviderType::tryFrom((string) config('pm.ai.provider'));

        return $provider && filled(config('pm.ai.model'))
            ? ['provider' => $provider, 'model' => config('pm.ai.model'), 'endpoint' => config('pm.ai.endpoint') ?: null, 'source' => 'env']
            : null;
    }

    private function stored(Workspace $workspace): ?array
    {
        $row = $this->row($workspace);

        // A row with no provider only holds the cap and usage.
        return $row?->provider ? ['provider' => $row->provider, 'model' => $row->model, 'endpoint' => $row->endpoint, 'source' => 'workspace'] : null;
    }

    /** What stops a connection being used: the key its provider needs, the endpoint openai_compatible needs, and an endpoint that fails AiEndpoint. @return list<string> */
    private function problems(array $connection, bool $hasKey): array
    {
        $endpoint = $connection['endpoint'];

        return array_values(array_filter([
            $connection['provider']->needsKey() && ! $hasKey ? 'key_missing' : null,
            $connection['provider'] === AiProviderType::OpenAiCompatible && blank($endpoint) ? 'endpoint_missing' : null,
            filled($endpoint) && AiEndpoint::problem($endpoint) !== null ? 'endpoint_not_allowed' : null,
        ]));
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
