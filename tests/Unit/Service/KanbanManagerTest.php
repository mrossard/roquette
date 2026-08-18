<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\KanbanColumn;
use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use App\Repository\KanbanColumnRepository;
use App\Service\ChannelAccessService;
use App\Service\KanbanManager;
use App\Service\MercurePublisher;
use App\Service\MessageRenderer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class KanbanManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private KanbanColumnRepository $kanbanColumnRepository;
    private MercurePublisher $mercurePublisher;
    private MessageRenderer $messageRenderer;
    private TranslatorInterface $translator;
    private ChannelAccessService $channelAccessService;
    private Environment $twig;
    private KanbanManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->kanbanColumnRepository = $this->createMock(KanbanColumnRepository::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->messageRenderer = $this->createMock(MessageRenderer::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->channelAccessService = $this->createMock(ChannelAccessService::class);
        $this->twig = $this->createMock(Environment::class);

        $this->translator->method('trans')->willReturnCallback(static fn(string $id) => $id);
        $this->channelAccessService->method('canUserAccess')->willReturn(true);

        $this->manager = new KanbanManager(
            $this->entityManager,
            $this->kanbanColumnRepository,
            $this->mercurePublisher,
            $this->messageRenderer,
            $this->translator,
            $this->channelAccessService,
            $this->twig,
        );
    }

    public function testInitializeDefaultColumnsDoesNothingForNonTodoList(): void
    {
        $channel = new Channel();
        $channel->setIsTodoList(false);

        $this->entityManager->expects(static::never())->method('persist');
        $this->entityManager->expects(static::never())->method('flush');

        $this->manager->initializeDefaultColumns($channel);
    }

    public function testInitializeDefaultColumnsCreatesDefaultsWhenEmpty(): void
    {
        $channel = new Channel();
        $channel->setIsTodoList(true);

        $this->kanbanColumnRepository
            ->expects(static::once())
            ->method('findByChannelOrdered')
            ->with($channel)
            ->willReturn([]);

        $this->entityManager
            ->expects(static::exactly(3))
            ->method('persist')
            ->with(static::isInstanceOf(KanbanColumn::class));
        $this->entityManager->expects(static::once())->method('flush');

        $this->manager->initializeDefaultColumns($channel);
    }

    public function testSyncCompletionFromReactionDoesNothingForNonTodoList(): void
    {
        $channel = new Channel();
        $channel->setIsTodoList(false);

        $message = new Message();
        $message->setChannel($channel);

        $user = new User();

        $this->entityManager->expects(static::never())->method('flush');
        $this->mercurePublisher->expects(static::never())->method('publishToChannel');

        $this->manager->syncCompletionFromReaction($message, $user, '✅');
    }

    public function testSyncCompletionFromReactionDoesNothingForNonCheckEmoji(): void
    {
        $channel = new Channel();
        $channel->setIsTodoList(true);

        $message = new Message();
        $message->setChannel($channel);

        $user = new User();

        $this->entityManager->expects(static::never())->method('flush');
        $this->mercurePublisher->expects(static::never())->method('publishToChannel');

        $this->manager->syncCompletionFromReaction($message, $user, '👍');
    }

    public function testSyncCompletionFromReactionMarksCompletedWhenCheckPresent(): void
    {
        $channel = new Channel();
        $channel->setIsTodoList(true);
        $channel->setSlug('todos');

        $user = new User();
        $user->setUsername('alice');

        $message = new Message();
        $message->setChannel($channel);
        $message->setIsCompleted(false);

        $reaction = new Reaction();
        $reaction->setMessage($message);
        $reaction->setUser($user);
        $reaction->setEmoji('✅');
        $message->getReactions()->add($reaction);

        $this->entityManager->expects(static::once())->method('flush');
        $this->twig->method('render')->willReturn('<div>Card HTML</div>');
        $this->mercurePublisher
            ->expects(static::once())
            ->method('publishToChannel')
            ->with($channel, static::isArray(), 'kanban_card_updated');

        $this->manager->syncCompletionFromReaction($message, $user, '✅');

        static::assertTrue($message->isCompleted());
    }

    public function testSyncCompletionFromReactionMarksIncompleteWhenNoCheck(): void
    {
        $channel = new Channel();
        $channel->setIsTodoList(true);
        $channel->setSlug('todos');

        $user = new User();
        $user->setUsername('alice');

        $message = new Message();
        $message->setChannel($channel);
        $message->setIsCompleted(true);

        $reaction = new Reaction();
        $reaction->setMessage($message);
        $reaction->setUser($user);
        $reaction->setEmoji('🚀');
        $message->getReactions()->add($reaction);

        $this->entityManager->expects(static::once())->method('flush');
        $this->twig->method('render')->willReturn('<div>Card HTML</div>');
        $this->mercurePublisher
            ->expects(static::once())
            ->method('publishToChannel')
            ->with($channel, static::isArray(), 'kanban_card_updated');

        $this->manager->syncCompletionFromReaction($message, $user, '✅');

        static::assertFalse($message->isCompleted());
    }
}
