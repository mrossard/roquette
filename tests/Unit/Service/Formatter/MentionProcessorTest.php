<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Formatter;

use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Service\Formatter\MentionProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class MentionProcessorTest extends TestCase
{
    public function testRendersUserMentionsAndChannelReferences(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $alice = new User();
        $alice->setUsername('alice');
        $alice->setDisplayName('Alice Wonder');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo
            ->expects($this->once())
            ->method('findBy')
            ->with(['username' => ['alice']])
            ->willReturn([$alice]);

        $general = new Channel();
        $general->setName('Général');
        $general->setSlug('general');
        $general->setIsPrivate(false);

        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo
            ->expects($this->once())
            ->method('findBy')
            ->with(['slug' => ['general'], 'isDm' => false])
            ->willReturn([$general]);

        $processor = new MentionProcessor($security, $userRepo, $channelRepo);

        $html = '<p>Bonjour @alice, rejoins #general !</p>';
        $processed = $processor->process($html);

        static::assertStringContainsString(
            '<a href="/dm/alice" class="mention" hx-boost="false">@Alice Wonder</a>',
            $processed,
        );
        static::assertStringContainsString(
            '<a href="/channels/general" class="channel-ref" hx-boost="false">#Général</a>',
            $processed,
        );
    }
}
