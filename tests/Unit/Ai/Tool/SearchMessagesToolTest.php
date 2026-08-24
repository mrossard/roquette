<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tool;

use App\Ai\Tool\SearchMessagesTool;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\HybridSearchService;
use PHPUnit\Framework\TestCase;

final class SearchMessagesToolTest extends TestCase
{
    public function testSearchMessagesReturnsResults(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $messageRepo = $this->createMock(MessageRepository::class);

        $user = new User();
        $userRepo->expects($this->once())->method('find')->with(1)->willReturn($user);

        $msg = new Message();
        $msg->setContent('Test search result');
        $msg->setCreatedAt(new \DateTimeImmutable());
        $messageRepo->expects($this->once())->method('searchGlobal')->willReturn([$msg]);

        $tool = new SearchMessagesTool($userRepo, $messageRepo);
        $result = $tool->execute(['query' => 'Test'], 1);

        static::assertArrayHasKey('results', $result);
        static::assertSame(1, $result['count']);
    }

    public function testSearchMessagesWithDocumentExcerpt(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $messageRepo = $this->createStub(MessageRepository::class);
        $hybridSearch = $this->createMock(HybridSearchService::class);

        $user = new User();
        $userRepo->expects($this->once())->method('find')->with(1)->willReturn($user);

        $msg = new Message();
        $msg->setContent('Voici la pièce jointe');
        $msg->setFileName('rapport.pdf');
        $msg->setCreatedAt(new \DateTimeImmutable());

        $hybridSearch->expects($this->once())->method('searchGlobal')->willReturn([$msg]);

        $hybridSearch
            ->expects($this->once())
            ->method('getMatchingDocumentExcerpt')
            ->willReturn('Extrait pertinent dans le PDF');

        $tool = new SearchMessagesTool($userRepo, $messageRepo, null, $hybridSearch);
        $result = $tool->execute(['query' => 'rapport'], 1);

        static::assertArrayHasKey('results', $result);
        static::assertSame(1, $result['count']);
        static::assertStringContainsString('rapport.pdf', $result['results']);
        static::assertStringContainsString('Extrait pertinent dans le PDF', $result['results']);
    }
}
