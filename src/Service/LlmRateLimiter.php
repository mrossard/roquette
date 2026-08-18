<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

readonly class LlmRateLimiter
{
    public const MESSAGE_KEY = 'rate_limit.assistant';

    public function __construct(
        #[Autowire(service: 'limiter.llm_api')]
        private RateLimiterFactoryInterface $llmApiLimiter,
    ) {}

    public function consume(User $user): bool
    {
        return $this->llmApiLimiter
            ->create('user_' . $user->getId())
            ->consume(1)
            ->isAccepted();
    }

    public function consumeConfirmation(User $user): bool
    {
        return $this->llmApiLimiter
            ->create('tool_confirm_' . $user->getId())
            ->consume(1)
            ->isAccepted();
    }
}
