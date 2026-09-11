<?php
/**
 * Shared secret for SSO with the Main LGU hub.
 *
 * The secret must come from the environment. There is deliberately no code
 * fallback: an earlier version shipped one committed to git, which meant any
 * clone of the repo could forge an SSO token. That exact value is now
 * blocklisted so it can never be used again even if someone pastes it back.
 */

/**
 * The usable SSO secret, or null when SSO must be refused (unset, too short,
 * or the known-compromised value).
 */
function frs_sso_shared_secret(): ?string
{
    // The value that leaked in git history. Never usable again.
    $compromised = '6724201881389f70d4d233dcd87caa15d507ebfd56f3fc73e0ad2b1c61e2d825';

    $secret = function_exists('env_value')
        ? trim((string) env_value('SSO_SHARED_SECRET', ''))
        : trim((string) (getenv('SSO_SHARED_SECRET') ?: ''));

    if ($secret === '' || strlen($secret) < 32 || hash_equals($compromised, $secret)) {
        return null;
    }

    return $secret;
}
