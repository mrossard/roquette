<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Ai\ChannelResolver;
use App\Ai\ChannelSummaryBuilder;
use App\Ai\DocumentContextBuilder;
use App\Ai\IntentClassifier;
use App\Ai\LlmIntentClassifier;
use App\Ai\Tool\ScheduleReminderTool;
use App\Ai\ToolRegistry;
use App\Ai\ToolRunner;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\MessageHandler\LlmQueryHandler;
use App\Repository\UserRepository;
use App\Service\ChannelAccessService;
use App\Service\DocChunker;
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
    /**
     * @param array<string, mixed> $overrides
     * @return array{0: LlmQueryHandler, 1: array<string, mixed>}
     */
    private function buildHandler(array $overrides = []): array
    {
        $defaults = [
            'userRepository' => $this->createMock(UserRepository::class),
            'channelRepository' => $this->createMock(\App\Repository\ChannelRepository::class),
            'messageRepository' => $this->createMock(\App\Repository\MessageRepository::class),
            'llmService' => $this->createMock(LlmService::class),
            'messageFormatter' => $this->createStub(MessageFormatter::class),
            'hub' => $this->createMock(HubInterface::class),
            'entityManager' => $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            'logger' => $this->createMock(\Psr\Log\LoggerInterface::class),
            'twig' => $this->createMock(\Twig\Environment::class),
            'workspaceRepository' => $this->createStub(\App\Repository\WorkspaceRepository::class),
            'userChannelReadRepository' => $this->createMock(\App\Repository\UserChannelReadRepository::class),
            'retriever' => (static function (): RetrieverInterface {
                $retriever = TestCase::createStub(RetrieverInterface::class);
                $retriever->method('retrieve')->willReturn([]);

                return $retriever;
            })(),
            'parameterBag' => (function () {
                $parameterBag = $this->createMock(ParameterBagInterface::class);
                $parameterBag->method('get')->willReturnCallback(static fn(string $name): ?string => $name === 'kernel.project_dir' ? '/tmp' : null);

                return $parameterBag;
            })(),
            'toolsEnabled' => false,
            'memoryMessages' => 10,
            'maxSummaryMessages' => 10,
        ];
        $deps = array_merge($defaults, $overrides);

        $deps['twig']->method('render')->willReturn('<div>test</div>');

        $channelResolver = new ChannelResolver($deps['channelRepository'], $deps['workspaceRepository']);
        $intentClassifier = new IntentClassifier(new LlmIntentClassifier($deps['llmService'], $deps['logger']));
        $summaryBuilder = new ChannelSummaryBuilder(
            $deps['userChannelReadRepository'],
            $deps['messageRepository'],
            $channelResolver,
            $deps['maxSummaryMessages'],
        );
        $documentContextBuilder = new DocumentContextBuilder(
            $deps['retriever'],
            new DocChunker(),
            $deps['logger'],
            $deps['parameterBag'],
        );

        $toolRegistry = $deps['toolRegistry'] ?? new ToolRegistry([]);
        $toolRunner = $deps['toolRunner'] ?? new ToolRunner($deps['llmService'], $toolRegistry);

        $handler = new LlmQueryHandler(
            userRepository: $deps['userRepository'],
            channelRepository: $deps['channelRepository'],
            messageRepository: $deps['messageRepository'],
            llmService: $deps['llmService'],
            messageFormatter: $deps['messageFormatter'],
            hub: $deps['hub'],
            entityManager: $deps['entityManager'],
            mercureTopicPrefix: 'roquette',
            logger: $deps['logger'],
            twig: $deps['twig'],
            toolRegistry: $toolRegistry,
            toolRunner: $toolRunner,
            workspaceRepository: $deps['workspaceRepository'],
            channelResolver: $channelResolver,
            intentClassifier: $intentClassifier,
            summaryBuilder: $summaryBuilder,
            documentContextBuilder: $documentContextBuilder,
            toolsEnabled: $deps['toolsEnabled'],
            memoryMessages: $deps['memoryMessages'],
        );

        return [$handler, $deps];
    }

    public function testHandlerInvokesLlmAndPublishesToMercure(): void
    {
        $user = new User();
        $user->setUsername('test_user');

        $generator = (static function () {
            yield 'Hello ';
            yield 'world!';
        })();

        $overrides = [
            'userRepository' => (function () use ($user) {
                $userRepository = $this->createMock(UserRepository::class);
                $userRepository->expects($this->once())->method('find')->with(42)->willReturn($user);

                return $userRepository;
            })(),
            'llmService' => (function () use ($generator) {
                $llmService = $this->createMock(LlmService::class);
                $llmService->expects($this->once())->method('generateTextStream')->willReturn($generator);

                return $llmService;
            })(),
            'messageFormatter' => (function () {
                $messageFormatter = $this->createStub(MessageFormatter::class);
                $messageFormatter->method('format')->willReturnCallback(static fn($text) => '<p>' . $text . '</p>');

                return $messageFormatter;
            })(),
            'hub' => (function () {
                $hub = $this->createMock(HubInterface::class);
                $hub->expects($this->atLeastOnce())->method('publish')->with($this->isInstanceOf(Update::class));

                return $hub;
            })(),
        ];

        [$handler] = $this->buildHandler($overrides);

        $message = new LlmQueryMessage('How does it work?', 42, 'general', 'help-123');
        $handler($message);
    }

    public function testSummaryLimitsMessages(): void
    {
        $user = new User();
        $user->setUsername('test_user');

        $channel = new \App\Entity\Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $msg = new \App\Entity\Message();
            $msg->setContent("Message {$i}");
            $msg->setAuthor($user);
            $msg->setCreatedAt(new \DateTimeImmutable());
            $messages[] = $msg;
        }

        $llmService = $this->createMock(LlmService::class);
        $llmService
            ->expects($this->exactly(2))
            ->method('generateText')
            ->willReturn('Résumé intermédiaire');

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

        $overrides = [
            'userRepository' => (function () use ($user) {
                $userRepository = $this->createMock(UserRepository::class);
                $userRepository->expects($this->once())->method('find')->with(42)->willReturn($user);

                return $userRepository;
            })(),
            'channelRepository' => (function () use ($channel) {
                $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
                $channelRepository->expects($this->once())->method('findAllForUser')->willReturn([$channel]);

                return $channelRepository;
            })(),
            'userChannelReadRepository' => (function () {
                $repo = $this->createMock(\App\Repository\UserChannelReadRepository::class);
                $repo->expects($this->once())->method('findOneBy')->willReturn(null);

                return $repo;
            })(),
            'messageRepository' => (function () use ($messages) {
                $repo = $this->createMock(\App\Repository\MessageRepository::class);
                $repo->expects($this->once())->method('findUnreadInChannel')->willReturn($messages);

                return $repo;
            })(),
            'llmService' => $llmService,
            'maxSummaryMessages' => 3,
        ];

        [$handler] = $this->buildHandler($overrides);

        $message = new LlmQueryMessage('résume le canal général', 42, 'dm-robot-roquette-1', 'help-123');
        $handler($message);
    }

    public function testSummaryPrependsLastReadMessages(): void
    {
        $user = new User();
        $user->setUsername('test_user');

        $channel = new \App\Entity\Channel();
        $channel->setName('general');
        $channel->setSlug('general');

        $lastReadMsg = $this->createMock(\App\Entity\Message::class);
        $lastReadMsg->method('getId')->willReturn(10);

        $activeRead = $this->createMock(\App\Entity\UserChannelRead::class);
        $activeRead->method('getLastReadMessage')->willReturn($lastReadMsg);

        $unread = [];
        for ($i = 1; $i <= 3; $i++) {
            $msg = new \App\Entity\Message();
            $msg->setContent("Unread {$i}");
            $msg->setAuthor($user);
            $msg->setCreatedAt(new \DateTimeImmutable());
            $unread[] = $msg;
        }

        $readMsg = new \App\Entity\Message();
        $readMsg->setContent('Read context');
        $readMsg->setAuthor($user);
        $readMsg->setCreatedAt(new \DateTimeImmutable());

        $qb = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([$readMsg]);

        $llmService = $this->createMock(LlmService::class);
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

        $overrides = [
            'userRepository' => (function () use ($user) {
                $userRepository = $this->createMock(UserRepository::class);
                $userRepository->expects($this->once())->method('find')->with(42)->willReturn($user);

                return $userRepository;
            })(),
            'channelRepository' => (function () use ($channel) {
                $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
                $channelRepository->expects($this->once())->method('findAllForUser')->willReturn([$channel]);

                return $channelRepository;
            })(),
            'userChannelReadRepository' => (function () use ($activeRead) {
                $repo = $this->createMock(\App\Repository\UserChannelReadRepository::class);
                $repo->expects($this->once())->method('findOneBy')->willReturn($activeRead);

                return $repo;
            })(),
            'messageRepository' => (function () use ($unread, $qb) {
                $repo = $this->createMock(\App\Repository\MessageRepository::class);
                $repo->expects($this->once())->method('findUnreadInChannel')->willReturn($unread);
                $repo->expects($this->once())->method('createQueryBuilder')->willReturn($qb);

                return $repo;
            })(),
            'llmService' => $llmService,
        ];

        [$handler] = $this->buildHandler($overrides);

        $message = new LlmQueryMessage('résume le canal général', 42, 'dm-robot-roquette-1', 'help-123');
        $handler($message);
    }

    public function testReminderToolCallIsExecutedThroughNativeToolLoop(): void
    {
        $user = new User();
        $user->setUsername('test_user');
        $user->setSlug('test-user');

        $channel = new \App\Entity\Channel();
        $channel->setName('Assistant');
        $channel->setSlug('assistant');

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

        $channelRepository = $this->createMock(\App\Repository\ChannelRepository::class);
        $channelRepository->method('findAllForUser')->willReturn([]);
        $channelRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use ($channel) {
            return ($criteria['slug'] ?? null) === 'assistant' ? $channel : null;
        });

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('find')->willReturn($user);

        $accessService = $this->createMock(ChannelAccessService::class);
        $accessService->method('canUserAccess')->willReturn(true);

        $llmService = $this->createMock(LlmService::class);

        $tool = new ScheduleReminderTool(
            $entityManager,
            $userRepository,
            $bus,
            new ChannelResolver($channelRepository, $this->createStub(\App\Repository\WorkspaceRepository::class)),
            $accessService,
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
        $messageFormatter = $this->createStub(MessageFormatter::class);
        $messageFormatter->method('format')->willReturnCallback(static function ($text) use (&$formattedTexts) {
            $formattedTexts[] = $text;

            return '<p>' . $text . '</p>';
        });

        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->atLeastOnce())->method('publish')->with(static::isInstanceOf(Update::class));

        $overrides = [
            'userRepository' => $userRepository,
            'channelRepository' => $channelRepository,
            'entityManager' => $entityManager,
            'llmService' => $llmService,
            'messageFormatter' => $messageFormatter,
            'hub' => $hub,
            'toolsEnabled' => true,
            'toolRegistry' => $toolRegistry,
            'toolRunner' => $toolRunner,
        ];

        [$handler] = $this->buildHandler($overrides);

        $message = new LlmQueryMessage('rappelle moi d\'aller manger à 15h22', 42, 'general', 'help-123');
        $handler($message);

        $this->assertCount(1, $persistedReminders);
        $this->assertSame('Aller manger', $persistedReminders[0]->getMessage());
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(\App\Message\SendReminderMessage::class, $dispatched[0]);
        $this->assertStringContainsString('Votre rappel est programmé', implode(' ', $formattedTexts));
    }
}
