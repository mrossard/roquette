<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\TypingIndicatorService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TypingIndicatorServiceTest extends TestCase
{
    public function testStartAndStopTyping(): void
    {
        $cache = new ArrayAdapter();
        $service = new TypingIndicatorService($cache);

        $channel = new Channel();
        $channel->setName('General');
        $channel->setSlug('general');

        $alice = new User();
        $alice->setUsername('alice');
        $alice->setDisplayName('Alice L.');

        $bob = new User();
        $bob->setUsername('bob');

        // Initially no one is typing
        static::assertSame([], $service->getTypingUsers($channel));

        // Alice starts typing
        $service->startTyping($channel, $alice);
        static::assertSame(['Alice L.'], $service->getTypingUsers($channel));

        // Bob starts typing
        $service->startTyping($channel, $bob);
        $typing = $service->getTypingUsers($channel);
        static::assertCount(2, $typing);
        static::assertContains('Alice L.', $typing);
        static::assertContains('bob', $typing);

        // When queried as Alice, Alice is excluded
        static::assertSame(['bob'], $service->getTypingUsers($channel, $alice));

        // Alice stops typing
        $service->stopTyping($channel, $alice);
        static::assertSame(['bob'], $service->getTypingUsers($channel));

        // Bob stops typing via updateTypingStatus(false)
        $service->updateTypingStatus($channel, $bob, false);
        static::assertSame([], $service->getTypingUsers($channel));
    }
}
