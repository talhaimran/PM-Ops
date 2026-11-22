<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\AiProviderType;
use App\Services\AiSettingsService;
use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Spec 8 (A5): the provider form. A blank key keeps the stored one; the key field is never flashed back (bootstrap/app.php). */
class UpdateAiSettingsRequest extends FormRequest
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

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

    // shortcut: any https host is accepted, so an Admin could point it at an internal address; resolve and block private ranges before Cloud makes provider calls (step B).
    private function endpointRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }
        $url = parse_url($value);
        $scheme = $url['scheme'] ?? '';
        $host = strtolower($url['host'] ?? '');
        if (! $host || ! ($scheme === 'https' || ($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true)))) {
            $fail('Use an https:// address, or http://localhost for a model on this machine.');
        } elseif (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            $fail('Leave the login, query and fragment out of the address. The key goes in the API key field.');
        }
    }
}
