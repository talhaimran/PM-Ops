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
    /** Container key holding the agent id for the code or token being issued right now. */
    private const AGENT = 'pm.oauth_agent';

    /**
     * Runs one issuance (a code on consent, or the token endpoint) with $agentId available to the repositories, and
     * always clears it afterwards, so a long-running worker never hands one agent to the next request.
     */
    public static function issuing(?int $agentId, callable $issue): mixed
    {
        self::useAgent($agentId);
        try {
            return $issue();
        } finally {
            app()->forgetInstance(self::AGENT);
        }
    }

    /** Set by the repositories mid-issuance, from the code being exchanged or the token being refreshed. */
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
