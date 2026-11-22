<?php

namespace App\Mcp\OAuth;

use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * laravel/mcp's dynamic client registration, limited to the hosts and schemes in config/mcp.php (from
 * PM_MCP_REDIRECT_DOMAINS / PM_MCP_REDIRECT_SCHEMES), and never a scheme that can run script, whatever is configured.
 */
class RegisterClientController extends OAuthRegisterController
{
    private const BLOCKED_SCHEMES = ['javascript', 'data', 'file', 'vbscript'];

    protected function isValidRedirectUri(string $value): bool
    {
        return ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), self::BLOCKED_SCHEMES, true)
            && parent::isValidRedirectUri($value);
    }
}
