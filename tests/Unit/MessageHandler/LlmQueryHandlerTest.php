<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


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

#[AllowMockObjectsWithoutExpectations]
class LlmQueryHandlerTest extends TestCase
{
    public function testHandlerInvokesLlmAndPublishesToMercure(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $llmService = $this->createMock(LlmService::class);
        $messageFormatter = $this->createStub(MessageFormatter::class);
        $hub = $this->createMock(HubInterface::class);
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $user = new User();
        $user->setUsername('test_user');

        $userRepository->expects($this->once())->method('find')->with(42)->willReturn($user);

        $parameterBag->expects($this->once())->method('get')->with('kernel.project_dir')->willReturn('/tmp');

        // Mock generator for streaming response
        $generatorClosure = static function () {
            yield 'Hello ';
            yield 'world!';
        };
        $generator = $generatorClosure();

        $llmService->expects($this->once())->method('generateTextStream')->willReturn($generator);

        $messageFormatter
            ->method('format')
            ->willReturnCallback(static fn($text) => '<p>' . $text . '</p>');

        $hub->expects($this->atLeastOnce())->method('publish')->with(static::isInstanceOf(Update::class));

        $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
        $messageRepository = $this->createMock(\App\Repository\MessageRepository::class);
        $userChannelReadRepository = $this->createMock(\App\Repository\UserChannelReadRepository::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<div>test</div>');

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
            $twig,
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

        $userRepository->expects($this->once())->method('find')->with(42)->willReturn($user);

        // Classification output and intermediate summaries
        $llmService
            ->expects($this->exactly(3))
            ->method('generateText')
            ->willReturnCallback(static function (string $prompt, ?string $systemPrompt = null) {
                if (str_contains($prompt, 'résume le canal général')) {
                    return json_encode(['intent' => 'resumer', 'channelSlug' => 'general']);
                }

                return 'Résumé intermédiaire';
            });

        $channel = new \App\Entity\Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $channelRepository->expects($this->once())->method('findAllForUser')->willReturn([$channel]);

        $userChannelReadRepository->expects($this->once())->method('findOneBy')->willReturn(null);

        // Return 5 messages
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $msg = new \App\Entity\Message();
            $msg->setContent("Message {$i}");
            $msg->setAuthor($user);
            $msg->setCreatedAt(new \DateTimeImmutable());
            $messages[] = $msg;
        }

        $messageRepository->expects($this->once())->method('findUnreadInChannel')->willReturn($messages);

        // We expect LLM to stream the final combination
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->with(static::callback(static fn(string $prompt) => str_contains($prompt, 'Résumé intermédiaire')))
            ->willReturn(
                (static function () {
                    yield 'Summary';
                })(),
            );

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<div>test</div>');

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
            $twig,
            3, // Limit to 3 messages
        );

        $message = new LlmQueryMessage('résume le canal général', 42, 'dm-robot-roquette-1', 'help-123');
        $handler($message);
    }

    public function testSummaryPrependsLastReadMessages(): void
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

        $userRepository->expects($this->once())->method('find')->with(42)->willReturn($user);

        // Classification output
        $llmService
            ->expects($this->once())
            ->method('generateText')
            ->willReturn(json_encode(['intent' => 'resumer', 'channelSlug' => 'general']));

        $channel = new \App\Entity\Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $channelRepository->expects($this->once())->method('findAllForUser')->willReturn([$channel]);

        $lastReadMsg = $this->createMock(\App\Entity\Message::class);
        $lastReadMsg->method('getId')->willReturn(10);

        $activeRead = $this->createMock(\App\Entity\UserChannelRead::class);
        $activeRead->method('getLastReadMessage')->willReturn($lastReadMsg);

        $userChannelReadRepository->expects($this->once())->method('findOneBy')->willReturn($activeRead);

        // Unread messages (3 messages)
        $unread = [];
        for ($i = 1; $i <= 3; $i++) {
            $msg = new \App\Entity\Message();
            $msg->setContent("Unread {$i}");
            $msg->setAuthor($user);
            $msg->setCreatedAt(new \DateTimeImmutable());
            $unread[] = $msg;
        }

        $messageRepository->expects($this->once())->method('findUnreadInChannel')->willReturn($unread);

        // Mock query builder for last 5 read messages
        $readMsg = new \App\Entity\Message();
        $readMsg->setContent('Read context');
        $readMsg->setAuthor($user);
        $readMsg->setCreatedAt(new \DateTimeImmutable());

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);

        $messageRepository->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$readMsg]);

        // We expect LLM to receive both the read message and unread messages (total 4 messages)
        $llmService
            ->expects($this->once())
            ->method('generateTextStream')
            ->with(static::callback(static function (string $prompt) {
                $data = json_decode($prompt, true);

                return is_array($data) && count($data) === 4 && $data[0]['contenu'] === 'Read context';
            }))
            ->willReturn(
                (static function () {
                    yield 'Summary';
                })(),
            );

        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<div>test</div>');

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
            $twig,
            10, // Large limit to avoid batching in this test
        );

        $message = new LlmQueryMessage('résume le canal général', 42, 'dm-robot-roquette-1', 'help-123');
        $handler($message);
    }
}
