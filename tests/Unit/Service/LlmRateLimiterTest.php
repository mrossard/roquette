<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\LlmRateLimiter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AllowMockObjectsWithoutExpectations]
final class LlmRateLimiterTest extends TestCase
{
    private function createUser(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    private function createLimiter(bool $accepted): LimiterInterface
    {
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn($accepted);

        $limiter = $this->createStub(LimiterInterface::class);
        $limiter->method('consume')->willReturn($rateLimit);

        return $limiter;
    }

    #[Test]
    public function consumeUsesUserScopedKey(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects($this->once())->method('create')->with('user_42')->willReturn($this->createLimiter(true));

        $rateLimiter = new LlmRateLimiter($factory);

        static::assertTrue($rateLimiter->consume($this->createUser(42)));
    }

    #[Test]
    public function consumeConfirmationUsesToolConfirmScopedKey(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory
            ->expects($this->once())
            ->method('create')
            ->with('tool_confirm_42')
            ->willReturn($this->createLimiter(true));

        $rateLimiter = new LlmRateLimiter($factory);

        static::assertTrue($rateLimiter->consumeConfirmation($this->createUser(42)));
    }

    #[Test]
    public function consumeReturnsFalseWhenRejected(): void
    {
        $factory = $this->createMock(RateLimiterFactoryInterface::class);
        $factory->expects($this->once())->method('create')->with('user_7')->willReturn($this->createLimiter(false));

        $rateLimiter = new LlmRateLimiter($factory);

        static::assertFalse($rateLimiter->consume($this->createUser(7)));
    }
}
