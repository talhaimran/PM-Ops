<?php

namespace App\Models;

use App\Enums\AiProviderType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec 8 (A5): a workspace's AI connection. The key is encrypted at rest and hidden from serialisation; read it only
 * through AiSettingsService::key().
 */
#[Fillable(['workspace_id', 'provider', 'model', 'endpoint', 'key', 'monthly_token_cap', 'tokens_used_month'])]
#[Hidden(['key'])]
class AiProvider extends Model
{
    protected function casts(): array
    {
        return [
            'provider' => AiProviderType::class,
            'key' => 'encrypted',
            'monthly_token_cap' => 'integer',
            'tokens_used_month' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
