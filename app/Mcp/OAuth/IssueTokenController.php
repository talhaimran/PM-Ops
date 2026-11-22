<?php

namespace App\Mcp\OAuth;

use Laravel\Passport\Http\Controllers\AccessTokenController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/** Passport's token endpoint, with the agent hand-off cleared after every exchange or refresh, failed ones included. */
class IssueTokenController extends AccessTokenController
{
    public function issueToken(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        return AgentAccessTokenRepository::issuing(null, fn () => parent::issueToken($psrRequest, $psrResponse));
    }
}
