<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\MessageHandler\LlmQueryHandler;
use App\Repository\UserRepository;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class LlmQueryHandlerTest extends TestCase
{
    public function testHandlerInvokesLlmAndPublishesToMercure(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $llmService = $this->createMock(LlmService::class);
        $messageFormatter = $this->createMock(MessageFormatter::class);
        $hub = $this->createMock(HubInterface::class);
        $parameterBag = $this->createMock(ParameterBagInterface::class);

        $user = new User();
        $user->setUsername('test_user');

        $userRepository
            ->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($user);

        $parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('kernel.project_dir')
            ->willReturn('/tmp');

        // Mock generator for streaming response
        $generatorClosure = function () {
            yield 'Hello ';
            yield 'world!';
        };
        $generator = $generatorClosure();

        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->willReturn($generator);

        $messageFormatter
            ->expects($this->any())
            ->method('format')
            ->willReturnCallback(fn($text) => '<p>'.$text.'</p>');

        $hub
            ->expects($this->atLeastOnce())
            ->method('publish')
            ->with($this->isInstanceOf(Update::class));

        $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
        $messageRepository = $this->createMock(\App\Repository\MessageRepository::class);
        $userChannelReadRepository = $this->createMock(\App\Repository\UserChannelReadRepository::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $handler = new LlmQueryHandler(
            $userRepository,
            $channelRepository,
            $messageRepository,
            $userChannelReadRepository,
            $llmService,
            $messageFormatter,
            $hub,
            $parameterBag,
            $entityManager,
            'roquette',
            $logger,
        );

        $message = new LlmQueryMessage('How does it work?', 42, 'general', 'help-123');
        $handler($message);
    }

    public function testSummaryLimitsMessages(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $llmService = $this->createMock(LlmService::class);
        $messageFormatter = $this->createMock(MessageFormatter::class);
        $hub = $this->createMock(HubInterface::class);
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
        $messageRepository = $this->createMock(\App\Repository\MessageRepository::class);
        $userChannelReadRepository = $this->createMock(\App\Repository\UserChannelReadRepository::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $user = new User();
        $user->setUsername('test_user');

        $userRepository
            ->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($user);

        // Classification output to return summary intent
        $llmService
            ->expects($this->any())
            ->method('generateText')
            ->willReturn(json_encode(['intent' => 'resumer', 'channelSlug' => 'general']));

        $channel = new \App\Entity\Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $channelRepository
            ->expects($this->once())
            ->method('findAllForUser')
            ->willReturn([$channel]);

        $userChannelReadRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->willReturn(null);

        // Return 5 messages
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $msg = new \App\Entity\Message();
            $msg->setContent("Message $i");
            $msg->setAuthor($user);
            $msg->setCreatedAt(new \DateTimeImmutable());
            $messages[] = $msg;
        }

        $messageRepository
            ->expects($this->once())
            ->method('findUnreadInChannel')
            ->willReturn($messages);

        // We expect LLM to be called with a prompt containing only the last 3 messages (Message 3, Message 4, Message 5)
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->with(
                $this->callback(function (string $prompt) {
                    $data = json_decode($prompt, true);

                    return is_array($data) && count($data) === 3 && $data[0]['contenu'] === 'Message 3';
                }),
            )
            ->willReturn(
                (function () {
                    yield 'Summary';
                })(),
            );

        $handler = new LlmQueryHandler(
            $userRepository,
            $channelRepository,
            $messageRepository,
            $userChannelReadRepository,
            $llmService,
            $messageFormatter,
            $hub,
            $parameterBag,
            $entityManager,
            'roquette',
            $logger,
            3, // Limit to 3 messages
        );

        $message = new LlmQueryMessage('résume le canal général', 42, 'dm-robot-roquette-1', 'help-123');
        $handler($message);
    }
}
