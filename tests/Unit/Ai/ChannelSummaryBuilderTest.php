<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\ChannelResolver;
use App\Ai\ChannelSummaryBuilder;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Repository\UserChannelReadRepository;
use App\Repository\WorkspaceRepository;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ChannelSummaryBuilderTest extends TestCase
{
    private function makeChannel(string $name, string $slug): Channel
    {
        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);

        return $channel;
    }

    private function makeMessage(string $content, string $author): Message
    {
        $msg = new Message();
        $msg->setContent($content);
        $msg->setCreatedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));

        return $msg;
    }

    private function buildBuilder(
        MessageRepository $messageRepo,
        ?UserChannelReadRepository $readRepo = null,
        int $maxSummaryMessages = 100,
        int $maxSummaryBatches = 5,
    ): ChannelSummaryBuilder {
        $readRepo ??= $this->createMock(UserChannelReadRepository::class);

        return new ChannelSummaryBuilder(
            $readRepo,
            $messageRepo,
            new ChannelResolver(
                $this->createMock(ChannelRepository::class),
                $this->createMock(WorkspaceRepository::class),
            ),
            $maxSummaryMessages,
            $maxSummaryBatches,
        );
    }

    public function testChannelNotFoundReturnsNotFoundPrompt(): void
    {
        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo->expects($this->never())->method('findUnreadInChannel');

        $builder = $this->buildBuilder($messageRepo);
        $user = new User();

        [$prompt] = $builder->build($user, [], 'general');

        static::assertStringContainsString('n\'as pas trouvé le canal', $prompt);
    }

    public function testBuildsStructuredMessagesPrompt(): void
    {
        $channel = $this->makeChannel('général', 'general');

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo
            ->expects($this->once())
            ->method('findUnreadInChannel')
            ->with($channel, static::isInstanceOf(User::class), null)
            ->willReturn([$this->makeMessage('Bonjour tout le monde', 'alice')]);

        $builder = $this->buildBuilder($messageRepo);
        $user = new User();

        [$prompt, $systemPrompt] = $builder->build($user, [$channel], 'general');

        static::assertStringContainsString('Bonjour tout le monde', $prompt);
        static::assertStringContainsString('auteur', $prompt);
        static::assertStringContainsString('Roquette', $systemPrompt);
    }

    public function testBatchesWhenMessagesExceedLimit(): void
    {
        $channel = $this->makeChannel('général', 'general');

        $messages = [
            $this->makeMessage('message un', 'alice'),
            $this->makeMessage('message deux', 'bob'),
            $this->makeMessage('message trois', 'charlie'),
        ];

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo
            ->expects($this->once())
            ->method('findUnreadInChannel')
            ->with($channel, static::isInstanceOf(User::class), null)
            ->willReturn($messages);

        $builder = $this->buildBuilder($messageRepo, maxSummaryMessages: 2);
        $user = new User();

        [$prompt, , $batches] = $builder->build($user, [$channel], 'general');

        static::assertSame('', $prompt);
        static::assertCount(2, $batches);
        static::assertCount(2, $batches[0]);
        static::assertCount(1, $batches[1]);
    }

    public function testBatchesAreCappedByMaxSummaryBatches(): void
    {
        $channel = $this->makeChannel('général', 'general');

        $messages = [];
        for ($i = 0; $i < 30; $i++) {
            $messages[] = $this->makeMessage('message ' . $i, 'alice');
        }

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo
            ->expects($this->once())
            ->method('findUnreadInChannel')
            ->with($channel, static::isInstanceOf(User::class), null)
            ->willReturn($messages);

        $builder = $this->buildBuilder($messageRepo, maxSummaryMessages: 10, maxSummaryBatches: 2);
        $user = new User();

        [$prompt, , $batches] = $builder->build($user, [$channel], 'general');

        static::assertSame('', $prompt);
        static::assertCount(2, $batches);
        static::assertCount(10, $batches[0]);
        static::assertCount(10, $batches[1]);
        static::assertStringContainsString('message 29', $batches[1][9]['contenu']);
    }

    public function testFallbackToLastMessagesWhenNoUnread(): void
    {
        $channel = $this->makeChannel('général', 'general');

        $messageRepo = $this->createMock(MessageRepository::class);
        $messageRepo->method('findUnreadInChannel')->willReturn([]);
        $messageRepo->method('findRecentInChannel')->willReturn([$this->makeMessage('ancien message', 'alice')]);

        $builder = $this->buildBuilder($messageRepo);
        $user = new User();

        [$prompt] = $builder->build($user, [$channel], 'general');

        static::assertStringContainsString('ancien message', $prompt);
    }
}
