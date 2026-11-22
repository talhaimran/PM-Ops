<?php

namespace App\Mcp\OAuth;

use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * A-D3: a code remembers the agent picked on the consent screen; at the token exchange it hands that agent to
 * AgentAccessTokenRepository (league checks the code with isAuthCodeRevoked just before issuing the token).
 */
class AgentAuthCodeRepository extends AuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        parent::persistNewAuthCode($authCodeEntity);
        Passport::authCode()->newQuery()->whereKey($authCodeEntity->getIdentifier())->update(['agent_id' => AgentAccessTokenRepository::agent()]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        $code = Passport::authCode()->newQuery()->whereKey($codeId)->where('revoked', false)->first();
        AgentAccessTokenRepository::useAgent($code?->agent_id);

        return ! $code;
    }
}
