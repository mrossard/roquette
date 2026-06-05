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
}
