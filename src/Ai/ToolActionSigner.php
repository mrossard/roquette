<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs and verifies one-time confirmation tokens for side-effect tool actions.
 *
 * The token carries the tool name, its arguments, the requesting user and a
 * short expiry. It is HMAC-signed with the application secret so only a token
 * issued by the backend can be used to trigger the confirmed action.
 */
final readonly class ToolActionSigner
{
    private const ALGORITHM = 'sha256';

    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        private string $secret,
        private int $ttl = 900,
    ) {}

    /**
     * Signs a confirmation token from a raw payload.
     *
     * The payload must contain at least 'tool', 'args' and 'uid'. An 'exp'
     * timestamp is added automatically. Returns a URL-safe token.
     *
     * @param array<string, mixed> $payload The action payload to sign.
     */
    public function sign(array $payload): string
    {
        $payload['exp'] = new \DateTimeImmutable()->getTimestamp() + $this->ttl;

        $encoded = $this->encode($payload);

        return $encoded . '.' . $this->hmac($encoded);
    }

    /**
     * Verifies a token for the given user.
     *
     * @return array<string, mixed>|null The token payload when valid, null when invalid, expired or tampered.
     */
    public function verify(#[\SensitiveParameter] string $token, int $userId): ?array
    {
        [$encoded, $mac] = array_pad(explode('.', $token, 2), 2, '');
        if ($encoded === '' || $mac === '' || !hash_equals($this->hmac($encoded), $mac)) {
            return null;
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($payload) || ($payload['uid'] ?? null) !== $userId) {
            return null;
        }

        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    private function hmac(string $encoded): string
    {
        return rtrim(strtr(base64_encode(hash_hmac(self::ALGORITHM, $encoded, $this->secret, true)), '+/', '-_'), '=');
    }
}
