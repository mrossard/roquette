<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tool;

use App\Ai\Tool\CreatePollTool;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\MessageFormatter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class CreatePollToolTest extends TestCase
{
    public function testCreatePollSuccessfully(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $channelRepo = $this->createMock(ChannelRepository::class);
        $userRepo = $this->createMock(UserRepository::class);
        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $messageFormatter = $this->createMock(MessageFormatter::class);
        $twig = $this->createMock(\Twig\Environment::class);

        $channel = new Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $user = new User();
        $user->setUsername('robot-roquette');

        $channelRepo->expects($this->any())->method('findOneBy')->with(['slug' => 'general'])->willReturn($channel);
        $userRepo->expects($this->any())->method('findOneBy')->with(['username' => 'robot-roquette'])->willReturn($user);
        $messageFormatter->expects($this->any())->method('format')->willReturn('formatted text');
        $messageRenderer = $this->createMock(\App\Service\MessageRenderer::class);
        $messageRenderer->expects($this->any())->method('renderFeedItem')->willReturn('<div>Poll HTML</div>');

        $em->expects($this->atLeast(2))->method('persist');
        $em->expects($this->once())->method('flush');
        $mercurePublisher->expects($this->once())->method('publishNewMessage');

        $tool = new CreatePollTool($em, $channelRepo, $userRepo, $mercurePublisher, $messageFormatter, $twig, $messageRenderer);

        $result = $tool->__invoke('general', 'Choix resto ?', ['Option A', 'Option B']);

        $this->assertStringContainsString('a été publié dans le canal #general', $result);
    }
}
