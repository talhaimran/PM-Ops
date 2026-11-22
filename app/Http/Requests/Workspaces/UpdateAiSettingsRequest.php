<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\AiProviderType;
use App\Services\AiEndpoint;
use App\Services\AiSettingsService;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Spec 8 (A5): the provider form. A blank key keeps the stored one; the key field is never flashed back (bootstrap/app.php). */
class UpdateAiSettingsRequest extends FormRequest
{
    /** The scheme is case-insensitive; store it in lowercase. */
    protected function prepareForValidation(): void
    {
        if (is_string($endpoint = $this->input('endpoint'))) {
            $this->merge(['endpoint' => preg_replace_callback('/^[a-z][a-z0-9+.-]*(?=:)/i', fn ($m) => strtolower($m[0]), $endpoint)]);
        }
    }

    public function rules(): array
    {
        $provider = AiProviderType::tryFrom((string) $this->input('provider'));
        $endpoint = $this->input('endpoint');
        $ai = app(AiSettingsService::class);
        $workspace = app(CurrentWorkspace::class)->get();
        // Providers that need a key ask for one the first time, and again when the provider or endpoint moves.
        $needsKey = $provider?->needsKey() && (! $ai->hasKey($workspace) || $ai->keyMoves($workspace, $provider, is_string($endpoint) ? $endpoint : null));

        return [
            'provider' => ['required', Rule::enum(AiProviderType::class)],
            'model' => ['required', 'string', 'max:100'],
            'endpoint' => [Rule::requiredIf($provider === AiProviderType::OpenAiCompatible), 'nullable', 'string', 'max:255', $this->endpointRule(...)],
            'api_key' => [Rule::requiredIf((bool) $needsKey), 'nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['api_key' => 'API key'];
    }

    /** Save-time only; the request-time SSRF guard (DNS resolve, pinned IP, redirects) is required before any provider call — see CLAUDE.md. */
    private function endpointRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value) && $message = AiEndpoint::problem($value)) {
            $fail($message);
        }
    }
}
