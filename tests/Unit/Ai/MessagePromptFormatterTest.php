<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\MessagePromptFormatter;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MessagePromptFormatterTest extends TestCase
{
    private MessagePromptFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MessagePromptFormatter();
    }

    #[Test]
    public function resolveAuthorNamePrefersDisplayNameThenUsernameThenRobot(): void
    {
        $msg1 = new Message();
        $user1 = new User();
        $user1->setUsername('alice');
        $user1->setDisplayName('Alice Dupont');
        $msg1->setAuthor($user1);

        $this->assertSame('Alice Dupont', $this->formatter->resolveAuthorName($msg1));

        $msg2 = new Message();
        $user2 = new User();
        $user2->setUsername('bob');
        $msg2->setAuthor($user2);

        $this->assertSame('bob', $this->formatter->resolveAuthorName($msg2));

        $msg3 = new Message();
        $this->assertSame('robot-roquette', $this->formatter->resolveAuthorName($msg3));
    }

    #[Test]
    public function formatLineFormatsWithoutDate(): void
    {
        $msg = new Message();
        $user = new User();
        $user->setUsername('alice');
        $msg->setAuthor($user);
        $msg->setContent('Bonjour le monde');

        $this->assertSame('alice: Bonjour le monde', $this->formatter->formatLine($msg));
    }

    #[Test]
    public function formatLineWithDateFormatsWithDateAndTruncates(): void
    {
        $msg = new Message();
        $user = new User();
        $user->setUsername('alice');
        $msg->setAuthor($user);
        $msg->setContent('1234567890');
        $msg->setCreatedAt(new \DateTimeImmutable('2026-08-17 12:30:00'));

        $this->assertSame('[17/08 12:30] alice: 12345', $this->formatter->formatLineWithDate($msg, 5));
    }

    #[Test]
    public function formatLineHandlesPollMessage(): void
    {
        $msg = new Message();
        $user = new User();
        $user->setUsername('alice');
        $msg->setAuthor($user);

        $poll = new Poll();
        $poll->setQuestion('Quel est votre plat préféré ?');
        $msg->setPoll($poll);

        $this->assertSame('alice: [Sondage] Quel est votre plat préféré ?', $this->formatter->formatLine($msg));
    }

    #[Test]
    public function formatSearchReferenceBuildsJumpToLinkAndFileInfo(): void
    {
        $channel = new Channel();
        $channel->setSlug('dev');

        $user = new User();
        $user->setUsername('alice');
        $user->setDisplayName('Alice');

        $msg = new Message();
        $msg->setChannel($channel);
        $msg->setAuthor($user);
        $msg->setContent('Regardez ce schéma');
        $msg->setFileName('architecture.pdf');
        $msg->setCreatedAt(new \DateTimeImmutable('2026-08-17 14:15:00'));

        $ref = new \ReflectionProperty(Message::class, 'id');
        $ref->setValue($msg, 42);

        $result = $this->formatter->formatSearchReference($msg);
        $this->assertSame('[Réf: #dev?jumpTo=42 | 17/08/2026 14:15] Alice: Regardez ce schéma [Fichier: architecture.pdf]', $result);
    }

    #[Test]
    public function formatStructuredReturnsExpectedArray(): void
    {
        $user = new User();
        $user->setUsername('alice');

        $msg = new Message();
        $msg->setAuthor($user);
        $msg->setContent('Discussion importante');
        $msg->setCreatedAt(new \DateTimeImmutable('2026-08-17 10:00:00'));

        $structured = $this->formatter->formatStructured($msg);
        $this->assertSame([
            'date' => '2026-08-17 10:00',
            'auteur' => 'alice',
            'contenu' => 'Discussion importante',
        ], $structured);
    }
}
