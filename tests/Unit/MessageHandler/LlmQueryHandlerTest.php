<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Ai\ChannelResolver;
use App\Ai\Tool\ScheduleReminderTool;
use App\Ai\ToolRegistry;
use App\Ai\ToolRunner;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\MessageHandler\LlmQueryHandler;
use App\Repository\UserRepository;
use App\Service\LlmService;
use App\Service\MessageFormatter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
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

        $messageFormatter->method('format')->willReturnCallback(static fn($text) => '<p>' . $text . '</p>');

        $hub->expects($this->atLeastOnce())->method('publish')->with(static::isInstanceOf(Update::class));

        $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
        $messageRepository = $this->createMock(\App\Repository\MessageRepository::class);
        $userChannelReadRepository = $this->createMock(\App\Repository\UserChannelReadRepository::class);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<div>test</div>');
        $retriever = $this->createStub(RetrieverInterface::class);

        $handler = new LlmQueryHandler(
            userRepository: $userRepository,
            channelRepository: $channelRepository,
            messageRepository: $messageRepository,
            userChannelReadRepository: $userChannelReadRepository,
            llmService: $llmService,
            messageFormatter: $messageFormatter,
            hub: $hub,
            parameterBag: $parameterBag,
            entityManager: $entityManager,
            mercureTopicPrefix: 'roquette',
            logger: $logger,
            twig: $twig,
            retriever: $retriever,
            toolRegistry: new ToolRegistry([]),
            toolRunner: new ToolRunner($llmService, new ToolRegistry([])),
            workspaceRepository: $this->createStub(\App\Repository\WorkspaceRepository::class),
            toolsEnabled: false,
            memoryMessages: 10,
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
        $retriever = $this->createStub(RetrieverInterface::class);

        $handler = new LlmQueryHandler(
            userRepository: $userRepository,
            channelRepository: $channelRepository,
            messageRepository: $messageRepository,
            userChannelReadRepository: $userChannelReadRepository,
            llmService: $llmService,
            messageFormatter: $messageFormatter,
            hub: $hub,
            parameterBag: $parameterBag,
            entityManager: $entityManager,
            mercureTopicPrefix: 'roquette',
            logger: $logger,
            twig: $twig,
            retriever: $retriever,
            toolRegistry: new ToolRegistry([]),
            toolRunner: new ToolRunner($llmService, new ToolRegistry([])),
            workspaceRepository: $this->createStub(\App\Repository\WorkspaceRepository::class),
            maxSummaryMessages: 3,
            toolsEnabled: false,
            memoryMessages: 10,
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
        $retriever = $this->createStub(RetrieverInterface::class);

        $handler = new LlmQueryHandler(
            userRepository: $userRepository,
            channelRepository: $channelRepository,
            messageRepository: $messageRepository,
            userChannelReadRepository: $userChannelReadRepository,
            llmService: $llmService,
            messageFormatter: $messageFormatter,
            hub: $hub,
            parameterBag: $parameterBag,
            entityManager: $entityManager,
            mercureTopicPrefix: 'roquette',
            logger: $logger,
            twig: $twig,
            retriever: $retriever,
            toolRegistry: new ToolRegistry([]),
            toolRunner: new ToolRunner($llmService, new ToolRegistry([])),
            workspaceRepository: $this->createStub(\App\Repository\WorkspaceRepository::class),
            maxSummaryMessages: 10,
            toolsEnabled: false,
            memoryMessages: 10,
        );

        $message = new LlmQueryMessage('résume le canal général', 42, 'dm-robot-roquette-1', 'help-123');
        $handler($message);
    }

    public function testReminderToolCallIsExecutedThroughNativeToolLoop(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $llmService = $this->createMock(LlmService::class);
        $messageFormatter = $this->createStub(MessageFormatter::class);
        $hub = $this->createMock(HubInterface::class);
        $parameterBag = $this->createMock(ParameterBagInterface::class);

        $user = new User();
        $user->setUsername('test_user');
        $user->setSlug('test-user');

        $userRepository->method('find')->willReturn($user);

        $parameterBag->method('get')->willReturn('/tmp');

        $channel = new \App\Entity\Channel();
        $channel->setName('Assistant');
        $channel->setSlug('assistant');

        $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
        $channelRepository->method('findAllForUser')->willReturn([]);
        $channelRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use ($channel) {
            return ($criteria['slug'] ?? null) === 'assistant' ? $channel : null;
        });

        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $persistedReminders = [];
        $entityManager->method('persist')->willReturnCallback(static function ($entity) use (&$persistedReminders) {
            if ($entity instanceof \App\Entity\Reminder) {
                $persistedReminders[] = $entity;
            }
        });
        $entityManager->method('flush')->willReturnCallback(static function () use (&$persistedReminders) {
            if ([] !== $persistedReminders) {
                $ref = new \ReflectionProperty(\App\Entity\Reminder::class, 'id');
                $ref->setValue($persistedReminders[0], 1);
            }
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $dispatched = [];
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$dispatched) {
            $dispatched[] = $message;

            return new Envelope($message);
        });

        $tool = new ScheduleReminderTool(
            $entityManager,
            $channelRepository,
            $userRepository,
            $bus,
            new ChannelResolver($channelRepository, $this->createStub(\App\Repository\WorkspaceRepository::class)),
        );
        $toolRegistry = new ToolRegistry([$tool]);
        $toolRunner = new ToolRunner($llmService, $toolRegistry);

        // First stream: the model requests the schedule_reminder tool.
        $firstStream = (static function () {
            yield new ToolCallComplete([new ToolCall('1', 'schedule_reminder', [
                'channelSlug' => 'assistant',
                'reminderText' => 'Aller manger',
                'delayMinutes' => 51,
            ])]);
        })();

        // Second stream: the model confirms the reminder once the tool has run.
        $secondStream = (static function () {
            yield new TextDelta("C'est noté ! Votre rappel est programmé.");
        })();

        $llmService
            ->expects($this->exactly(2))
            ->method('generateStreamWithTools')
            ->willReturnOnConsecutiveCalls($firstStream, $secondStream);

        $formattedTexts = [];
        $messageFormatter->method('format')->willReturnCallback(static function ($text) use (&$formattedTexts) {
            $formattedTexts[] = $text;

            return '<p>' . $text . '</p>';
        });

        $hub->expects($this->atLeastOnce())->method('publish')->with(static::isInstanceOf(Update::class));

        $messageRepository = $this->createMock(\App\Repository\MessageRepository::class);
        $userChannelReadRepository = $this->createMock(\App\Repository\UserChannelReadRepository::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $twig = $this->createMock(\Twig\Environment::class);
        $twig->method('render')->willReturn('<div>test</div>');
        $retriever = $this->createStub(RetrieverInterface::class);

        $handler = new LlmQueryHandler(
            userRepository: $userRepository,
            channelRepository: $channelRepository,
            messageRepository: $messageRepository,
            userChannelReadRepository: $userChannelReadRepository,
            llmService: $llmService,
            messageFormatter: $messageFormatter,
            hub: $hub,
            parameterBag: $parameterBag,
            entityManager: $entityManager,
            mercureTopicPrefix: 'roquette',
            logger: $logger,
            twig: $twig,
            retriever: $retriever,
            toolRegistry: $toolRegistry,
            toolRunner: $toolRunner,
            workspaceRepository: $this->createStub(\App\Repository\WorkspaceRepository::class),
            toolsEnabled: true,
            memoryMessages: 10,
        );

        $message = new LlmQueryMessage('rappelle moi d\'aller manger à 15h22', 42, 'general', 'help-123');
        $handler($message);

        $this->assertCount(1, $persistedReminders);
        $this->assertSame('Aller manger', $persistedReminders[0]->getMessage());
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(\App\Message\SendReminderMessage::class, $dispatched[0]);
        $this->assertStringContainsString('Votre rappel est programmé', implode(' ', $formattedTexts));
    }
}
