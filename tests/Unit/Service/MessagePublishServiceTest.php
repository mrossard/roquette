<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use App\Message\ModerateMessageMessage;
use App\Repository\MessageRepository;
use App\Service\FileUploadService;
use App\Service\LlmRateLimiter;
use App\Service\MercurePublisher;
use App\Service\MessagePublishService;
use App\Service\MessageRenderer;
use App\Service\RobotUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class MessagePublishServiceTest extends TestCase
{
    private MessageRepository $messageRepository;
    private EntityManagerInterface $entityManager;
    private MercurePublisher $mercurePublisher;
    private FileUploadService $fileUploadService;
    private MessageBusInterface $messageBus;
    private TranslatorInterface $translator;
    private MessageRenderer $messageRenderer;
    private Environment $twig;
    private LlmRateLimiter $llmRateLimiter;
    private RobotUserProvider $robotUserProvider;
    private MessagePublishService $publishService;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->fileUploadService = $this->createMock(FileUploadService::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->messageRenderer = $this->createMock(MessageRenderer::class);
        $this->twig = $this->createMock(Environment::class);
        $this->llmRateLimiter = $this->createMock(LlmRateLimiter::class);
        $this->robotUserProvider = $this->createMock(RobotUserProvider::class);

        $this->translator->method('trans')->willReturnArgument(0);
        $this->messageRenderer->method('renderFeedItem')->willReturn('<div class="feed-item">Message</div>');
        $this->messageRepository->method('findLatestInChannel')->willReturn([]);
        $this->twig->method('render')->willReturn('<div hx-swap-oob="beforeend:#live-feed">...</div>');

        $this->publishService = new MessagePublishService(
            $this->messageRepository,
            $this->entityManager,
            $this->mercurePublisher,
            $this->fileUploadService,
            $this->messageBus,
            $this->translator,
            $this->messageRenderer,
            $this->twig,
            $this->llmRateLimiter,
            $this->robotUserProvider,
            new \App\Service\PollFactory(),
        );
    }

    #[Test]
    public function emptyMessageReturnsEmptyPublishResult(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $user,
            messageText: '   ',
        );

        $this->assertFalse($result->success);
        $this->assertSame($channel, $result->channel);
        $this->assertNull($result->message);
    }

    #[Test]
    public function pollWithLessThanTwoOptionsFails(): void
    {
        $channel = new Channel();
        $user = new User();

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $user,
            messageText: '',
            pollQuestion: 'Which color?',
            pollOptions: ['Blue only'],
        );

        $this->assertFalse($result->success);
        $this->assertSame(400, $result->statusCode);
        $this->assertSame('Un sondage requiert au moins 2 options.', $result->error);
    }

    #[Test]
    public function publishValidTextMessagePersistsAndBroadcasts(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();
        $userRef = new \ReflectionProperty(User::class, 'id');
        $userRef->setValue($user, 1);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->willReturnCallback(static function (Message $m) {
                $ref = new \ReflectionProperty(Message::class, 'id');
                $ref->setValue($m, 42);
            });
        $this->entityManager->expects($this->once())->method('flush');

        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ModerateMessageMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $this->mercurePublisher->expects($this->once())
            ->method('publishNewMessage')
            ->with(
                $channel,
                $this->isInstanceOf(Message::class),
                $user,
                'Hello world',
                '<div class="feed-item">Message</div>',
            );

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $user,
            messageText: 'Hello world',
        );

        $this->assertTrue($result->success);
        $this->assertNotNull($result->message);
        $this->assertSame('Hello world', $result->message->getContent());
        $this->assertSame('<div class="feed-item">Message</div>', $result->renderedHtml);
    }

    #[Test]
    public function robotMentionInChannelDispatchesLlmMessageWithoutPersisting(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();
        $userRef = new \ReflectionProperty(User::class, 'id');
        $userRef->setValue($user, 1);

        $robot = new User();
        $robot->setUsername(User::ROBOT_USERNAME);
        $this->robotUserProvider->method('getRobotUser')->willReturn($robot);
        $this->llmRateLimiter->method('consume')->willReturn(true);

        $this->entityManager->expects($this->never())->method('persist');
        $this->messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(LlmQueryMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $user,
            messageText: '@' . User::ROBOT_USERNAME . ' what is the weather?',
        );

        $this->assertTrue($result->success);
    }

    #[Test]
    public function invalidFileUploadReturnsErrorPublishResult(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');
        $user = new User();

        $file = $this->createMock(\Symfony\Component\HttpFoundation\File\UploadedFile::class);
        $this->fileUploadService
            ->method('uploadAndAttachToMessage')
            ->willThrowException(new \InvalidArgumentException('L\'extension de fichier ".3gp" n\'est pas autorisée.'));

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $user,
            messageText: '',
            file: $file,
        );

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame('L\'extension de fichier ".3gp" n\'est pas autorisée.', $result->error);
    }
}
