<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Message\EditMessageDto;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\User;
use App\Message\ModerateMessageMessage;
use App\Repository\MessageRepository;
use App\Service\MessageBroadcaster;
use App\Service\MessageEditor;
use App\Service\MessageRenderer;
use App\Service\PollFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class MessageEditorTest extends TestCase
{
    private MessageRepository $messageRepo;
    private EntityManagerInterface $em;
    private MessageBroadcaster $broadcaster;
    private MessageRenderer $renderer;
    private PollFactory $pollFactory;
    private TranslatorInterface $translator;
    private MessageBusInterface $messageBus;
    private MessageEditor $editor;

    protected function setUp(): void
    {
        $this->messageRepo = $this->createMock(MessageRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->broadcaster = $this->createMock(MessageBroadcaster::class);
        $this->renderer = $this->createMock(MessageRenderer::class);
        $this->pollFactory = $this->createMock(PollFactory::class);
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);
        $this->messageBus = $this->createMock(MessageBusInterface::class);

        $this->editor = new MessageEditor(
            $this->messageRepo,
            $this->em,
            $this->broadcaster,
            $this->renderer,
            $this->pollFactory,
            $this->translator,
            $this->messageBus,
        );
    }

    #[Test]
    public function getEditableMessageThrowsNotFoundWhenMissing(): void
    {
        $this->messageRepo->expects($this->once())->method('find')->with(1)->willReturn(null);
        $user = new User();

        $this->expectException(NotFoundHttpException::class);
        $this->editor->getEditableMessage(1, $user);
    }

    #[Test]
    public function getEditableMessageThrowsAccessDeniedForNonAuthor(): void
    {
        $author = new User();
        $otherUser = new User();

        $message = new Message();
        $message->setAuthor($author);

        $this->messageRepo->expects($this->once())->method('find')->with(1)->willReturn($message);

        $this->expectException(AccessDeniedHttpException::class);
        $this->editor->getEditableMessage(1, $otherUser);
    }

    #[Test]
    public function getEditableMessageThrowsBadRequestWhenPollHasVotes(): void
    {
        $author = new User();

        $poll = $this->createStub(Poll::class);
        $poll->method('getTotalVotes')->willReturn(3);

        $message = new Message();
        $message->setAuthor($author);
        $message->setPoll($poll);

        $this->messageRepo->expects($this->once())->method('find')->with(1)->willReturn($message);

        $this->expectException(BadRequestHttpException::class);
        $this->editor->getEditableMessage(1, $author);
    }

    #[Test]
    public function editUpdatesTextMessageAndBroadcasts(): void
    {
        $author = new User();
        $channel = new Channel();
        $channel->setSlug('general');

        $message = new Message();
        $message->setAuthor($author);
        $message->setChannel($channel);
        $message->setContent('Ancien contenu');

        $ref = new \ReflectionProperty(Message::class, 'id');
        $ref->setValue($message, 42);

        $this->messageRepo->expects($this->once())->method('find')->with(42)->willReturn($message);
        $this->em->expects($this->once())->method('flush');
        $this->renderer
            ->expects($this->once())
            ->method('renderFeedItem')
            ->with($message, ['no_fade' => true])
            ->willReturn('<div>Nouveau contenu</div>');
        $this->broadcaster->expects($this->once())->method('broadcastMessageUpdate')->with($message);
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(ModerateMessageMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

        $dto = new EditMessageDto(content: 'Nouveau contenu');
        $result = $this->editor->edit(42, $author, $dto);

        $this->assertTrue($result->success);
        $this->assertSame('<div>Nouveau contenu</div>', $result->renderedHtml);
        $this->assertSame('Nouveau contenu', $message->getContent());
    }

    #[Test]
    public function editPollDelegatesToPollFactory(): void
    {
        $author = new User();
        $channel = new Channel();
        $channel->setSlug('general');

        $poll = new Poll();

        $message = new Message();
        $message->setAuthor($author);
        $message->setChannel($channel);
        $message->setPoll($poll);

        $this->messageRepo->expects($this->once())->method('find')->with(10)->willReturn($message);
        $this->pollFactory
            ->expects($this->once())
            ->method('updatePoll')
            ->with($poll, 'Nouvelle question ?', ['Option A', 'Option B'], true);
        $this->em->expects($this->once())->method('flush');
        $this->renderer
            ->expects($this->once())
            ->method('renderFeedItem')
            ->with($message, ['no_fade' => true])
            ->willReturn('<div>Sondage mis à jour</div>');
        $this->broadcaster->expects($this->once())->method('broadcastMessageUpdate')->with($message);

        $dto = new EditMessageDto(
            pollQuestion: 'Nouvelle question ?',
            pollOptions: ['Option A', 'Option B'],
            allowMultiple: true,
        );
        $result = $this->editor->edit(10, $author, $dto);

        $this->assertTrue($result->success);
        $this->assertSame('<div>Sondage mis à jour</div>', $result->renderedHtml);
    }

    #[Test]
    public function editReturnsErrorWhenPollAlreadyHasVotes(): void
    {
        $author = new User();
        $poll = $this->createStub(Poll::class);
        $poll->method('getTotalVotes')->willReturn(2);

        $message = new Message();
        $message->setAuthor($author);
        $message->setPoll($poll);

        $this->messageRepo->expects($this->once())->method('find')->with(5)->willReturn($message);

        $dto = new EditMessageDto(pollQuestion: 'Nouvelle question ?');
        $result = $this->editor->edit(5, $author, $dto);

        $this->assertFalse($result->success);
        $this->assertSame('Impossible de modifier un sondage qui a déjà des votes.', $result->error);
        $this->assertSame(400, $result->statusCode);
        $this->assertSame($message, $result->message);
    }

    #[Test]
    public function editReturnsErrorWhenContentIsEmpty(): void
    {
        $author = new User();
        $message = new Message();
        $message->setAuthor($author);
        $message->setContent('Ancien message');

        $this->messageRepo->expects($this->once())->method('find')->with(8)->willReturn($message);

        $dto = new EditMessageDto(content: '   ');
        $result = $this->editor->edit(8, $author, $dto);

        $this->assertFalse($result->success);
        $this->assertSame('Le message ne peut pas être vide.', $result->error);
        $this->assertSame(400, $result->statusCode);
        $this->assertSame($message, $result->message);
    }
}
