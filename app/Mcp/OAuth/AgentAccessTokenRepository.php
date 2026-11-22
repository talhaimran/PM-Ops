<?php

namespace App\Mcp\OAuth;

use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;

/**
 * A-D3: every OAuth access token acts as one agent. The agent comes from the code being exchanged, or, on a refresh,
 * from the token being replaced (league revokes it just before issuing the new one). None = refused on /mcp.
 */
class AgentAccessTokenRepository extends AccessTokenRepository
{
    /** Container key holding the agent id for the token being issued in this request. */
    private const AGENT = 'pm.oauth_agent';

    public static function useAgent(?int $agentId): void
    {
        app()->instance(self::AGENT, $agentId);
    }

    public static function agent(): ?int
    {
        return app()->bound(self::AGENT) ? app(self::AGENT) : null;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        parent::persistNewAccessToken($accessTokenEntity);
        Passport::token()->newQuery()->whereKey($accessTokenEntity->getIdentifier())->update(['agent_id' => self::agent()]);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        self::useAgent(Passport::token()->newQuery()->whereKey($tokenId)->value('agent_id'));
        parent::revokeAccessToken($tokenId);
    }
}
