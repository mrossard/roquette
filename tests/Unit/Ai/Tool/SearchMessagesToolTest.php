<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Tool;

use App\Ai\Tool\SearchMessagesTool;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
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

        $this->assertArrayHasKey('results', $result);
        $this->assertSame(1, $result['count']);
    }
}
