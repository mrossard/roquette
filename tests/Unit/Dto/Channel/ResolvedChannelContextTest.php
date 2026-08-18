<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Channel;

use App\Dto\Channel\ResolvedChannelContext;
use App\Entity\Channel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResolvedChannelContextTest extends TestCase
{
    #[Test]
    public function memberWithExplicitNotificationsEnabledTrue(): void
    {
        $channel = new Channel();
        $this->setEntityId($channel, 10);
        $channel->setIsDm(false);

        $context = new ResolvedChannelContext($channel, true);
        $unreadCounts = [
            10 => ['notificationsEnabled' => true],
        ];

        static::assertTrue($context->resolveNotificationSetting($unreadCounts));
    }

    #[Test]
    public function memberWithExplicitNotificationsEnabledFalse(): void
    {
        $channel = new Channel();
        $this->setEntityId($channel, 10);
        $channel->setIsDm(true);

        $context = new ResolvedChannelContext($channel, true);
        $unreadCounts = [
            10 => ['notificationsEnabled' => false],
        ];

        static::assertFalse($context->resolveNotificationSetting($unreadCounts));
    }

    #[Test]
    public function memberWithoutPreferenceOnStandardChannel(): void
    {
        $channel = new Channel();
        $this->setEntityId($channel, 10);
        $channel->setIsDm(false);

        $context = new ResolvedChannelContext($channel, true);
        $unreadCounts = [];

        static::assertFalse($context->resolveNotificationSetting($unreadCounts));
    }

    #[Test]
    public function memberWithoutPreferenceOnDmChannel(): void
    {
        $channel = new Channel();
        $this->setEntityId($channel, 10);
        $channel->setIsDm(true);

        $context = new ResolvedChannelContext($channel, true);
        $unreadCounts = [];

        static::assertTrue($context->resolveNotificationSetting($unreadCounts));
    }

    #[Test]
    public function nonMemberFallsBackToIsDm(): void
    {
        $dmChannel = new Channel();
        $this->setEntityId($dmChannel, 1);
        $dmChannel->setIsDm(true);

        $regularChannel = new Channel();
        $this->setEntityId($regularChannel, 2);
        $regularChannel->setIsDm(false);

        $dmContext = new ResolvedChannelContext($dmChannel, false);
        $regularContext = new ResolvedChannelContext($regularChannel, false);

        $unreadCounts = [
            1 => ['notificationsEnabled' => false],
            2 => ['notificationsEnabled' => true],
        ];

        static::assertTrue($dmContext->resolveNotificationSetting($unreadCounts));
        static::assertFalse($regularContext->resolveNotificationSetting($unreadCounts));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
