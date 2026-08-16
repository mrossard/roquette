<?php

declare(strict_types=1);

namespace App\Security\OAuth;

/**
 * Utility methods for PKCE (RFC 7636) code verifier and code challenge generation.
 */
final class PkceUtil
{
    /**
     * Generate a cryptographically random code_verifier for PKCE (RFC 7636).
     *
     * Returns a base64url-encoded string without padding (43-128 chars).
     */
    public static function generateCodeVerifier(): string
    {
        return self::base64urlEncode(random_bytes(32));
    }

    /**
     * Compute the SHA-256 code challenge for a given code verifier.
     */
    public static function computeCodeChallenge(string $codeVerifier): string
    {
        return self::base64urlEncode(hash('sha256', $codeVerifier, true));
    }

    public static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
