<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\ToolActionSigner;
use PHPUnit\Framework\TestCase;

final class ToolActionSignerTest extends TestCase
{
    private function makeSigner(int $ttl = 900): ToolActionSigner
    {
        return new ToolActionSigner('test-secret', $ttl);
    }

    public function testSignedTokenRoundTrips(): void
    {
        $signer = $this->makeSigner();

        $token = $signer->sign([
            'tool' => 'create_poll',
            'args' => ['channelSlug' => 'general', 'question' => 'Quel choix ?'],
            'uid' => 42,
            'ws' => 7,
            'helpMessageId' => 'help-abc',
            'channelSlug' => 'general',
        ]);

        $payload = $signer->verify($token, 42);

        static::assertNotNull($payload);
        static::assertSame('create_poll', $payload['tool']);
        static::assertSame(['channelSlug' => 'general', 'question' => 'Quel choix ?'], $payload['args']);
        static::assertSame(42, $payload['uid']);
        static::assertSame(7, $payload['ws']);
        static::assertSame('help-abc', $payload['helpMessageId']);
        static::assertSame('general', $payload['channelSlug']);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $signer = $this->makeSigner();
        $token = $signer->sign(['tool' => 'create_poll', 'args' => [], 'uid' => 42]);

        static::assertNull($signer->verify($token . 'x', 42));
    }

    public function testWrongUserIsRejected(): void
    {
        $signer = $this->makeSigner();
        $token = $signer->sign(['tool' => 'create_poll', 'args' => [], 'uid' => 42]);

        static::assertNull($signer->verify($token, 43));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $signer = $this->makeSigner(ttl: -1);
        $token = $signer->sign(['tool' => 'create_poll', 'args' => [], 'uid' => 42]);

        static::assertNull($signer->verify($token, 42));
    }

    public function testGarbageIsRejected(): void
    {
        static::assertNull($this->makeSigner()->verify('not-a-valid-token', 1));
    }
}
