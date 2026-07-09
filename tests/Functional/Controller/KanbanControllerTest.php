<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\KanbanColumn;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class KanbanControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Workspace $workspace;
    private Channel $todoChannel;
    private KanbanColumn $todoColumn;
    private Message $testMessage;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        // Create a test user
        $user = new User();
        $user->setUsername('test_kanban_user');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        // Create a test Workspace
        $workspace = new Workspace();
        $workspace->setName('Kanban WS');
        $workspace->setSlug('kanban-ws');
        $workspace->setCreator($user);
        $workspace->addMember($user);
        $this->entityManager->persist($workspace);

        // Create a test channel (todo list)
        $channel = new Channel();
        $channel->setName('Kanban Todo');
        $channel->setSlug('kanban-todo');
        $channel->setIsTodoList(true);
        $channel->setCreator($user);
        $channel->addMember($user);
        $channel->setWorkspace($workspace);
        $this->entityManager->persist($channel);

        // Create a test Kanban Column
        $column = new KanbanColumn();
        $column->setName('To Do');
        $column->setPosition(0);
        $column->setChannel($channel);
        $this->entityManager->persist($column);

        // Create a test message (which acts as a task card)
        $message = new Message();
        $message->setContent('Test Task Card');
        $message->setAuthor($user);
        $message->setChannel($channel);
        $message->setKanbanColumn($column);
        $this->entityManager->persist($message);

        $this->entityManager->flush();

        $this->testUser = $user;
        $this->workspace = $workspace;
        $this->todoChannel = $channel;
        $this->todoColumn = $column;
        $this->testMessage = $message;

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $users = $userRepository->findBy(['username' => 'test_kanban_user']);

        $workspaceRepository = $this->entityManager->getRepository(Workspace::class);
        $workspaces = $workspaceRepository->findBy(['slug' => 'kanban-ws']);

        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $channels = $channelRepository->findBy(['slug' => 'kanban-todo']);

        $columnRepository = $this->entityManager->getRepository(KanbanColumn::class);
        $messageRepository = $this->entityManager->getRepository(Message::class);

        foreach ($channels as $channel) {
            $messages = $messageRepository->findBy(['channel' => $channel]);
            foreach ($messages as $message) {
                $this->entityManager->remove($message);
            }
            $columns = $columnRepository->findBy(['channel' => $channel]);
            foreach ($columns as $column) {
                $this->entityManager->remove($column);
            }
        }
        $this->entityManager->flush();

        foreach ($channels as $channel) {
            $this->entityManager->remove($channel);
        }
        foreach ($workspaces as $ws) {
            $this->entityManager->remove($ws);
        }
        foreach ($users as $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
    }

    #[Test]
    public function testKanbanBoard(): void
    {
        // 1. Regular GET request
        $this->client->request('GET', sprintf('/channels/%s/kanban', $this->todoChannel->getSlug()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1, h2, h3, div', 'Kanban Todo');

        // 2. HTMX request returning the board partial
        $this->client->request(
            'GET',
            sprintf('/channels/%s/kanban', $this->todoChannel->getSlug()),
            [],
            [],
            [
                'HTTP_HX-Request' => 'true',
                'HTTP_HX-Target' => 'kanban-board',
            ]
        );
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testCreateColumn(): void
    {
        $this->client->request(
            'POST',
            '/kanban/columns',
            [
                'channelId' => $this->todoChannel->getId(),
                'name' => 'In Progress',
                'color' => '#ff0000',
            ]
        );

        $this->assertResponseRedirects(sprintf('/channels/%s/kanban', $this->todoChannel->getSlug()));

        $this->entityManager->clear();
        $columns = $this->entityManager->getRepository(KanbanColumn::class)->findBy(['channel' => $this->todoChannel]);
        static::assertCount(2, $columns);
        
        $names = array_map(static fn($col) => $col->getName(), $columns);
        static::assertContains('In Progress', $names);
    }

    #[Test]
    public function testRenameColumn(): void
    {
        $this->client->request(
            'POST',
            sprintf('/kanban/columns/%d/rename', $this->todoColumn->getId()),
            [
                'name' => 'Renamed Column Name',
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $column = $this->entityManager->getRepository(KanbanColumn::class)->find($this->todoColumn->getId());
        static::assertSame('Renamed Column Name', $column->getName());
    }

    #[Test]
    public function testDeleteColumn(): void
    {
        $columnId = $this->todoColumn->getId();
        $this->client->request('POST', sprintf('/kanban/columns/%d/delete', $columnId));
        $this->assertResponseRedirects(sprintf('/channels/%s/kanban', $this->todoChannel->getSlug()));

        $this->entityManager->clear();
        $column = $this->entityManager->getRepository(KanbanColumn::class)->find($columnId);
        static::assertNull($column);

        // Check that message inside the deleted column is now untriaged (column = null)
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertNotNull($message);
        static::assertNull($message->getKanbanColumn());
    }

    #[Test]
    public function testReorderColumns(): void
    {
        // Create a second column to reorder
        $col2 = new KanbanColumn();
        $col2->setName('Col 2');
        $col2->setPosition(1);
        $col2->setChannel($this->todoChannel);
        $this->entityManager->persist($col2);
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/kanban/columns/reorder',
            [
                'columnIds' => [$col2->getId(), $this->todoColumn->getId()],
            ]
        );

        $this->assertResponseStatusCodeSame(204);

        $this->entityManager->clear();
        $c1 = $this->entityManager->getRepository(KanbanColumn::class)->find($this->todoColumn->getId());
        $c2 = $this->entityManager->getRepository(KanbanColumn::class)->find($col2->getId());

        // Col 2 should be position 0, To Do should be position 1
        static::assertSame(1, $c1->getPosition());
        static::assertSame(0, $c2->getPosition());
    }

    #[Test]
    public function testMoveMessage(): void
    {
        // Create another column to move the message to
        $col2 = new KanbanColumn();
        $col2->setName('Done');
        $col2->setPosition(1);
        $col2->setChannel($this->todoChannel);
        $this->entityManager->persist($col2);
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            sprintf('/messages/%d/kanban-column', $this->testMessage->getId()),
            [
                'columnId' => $col2->getId(),
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertSame($col2->getId(), $message->getKanbanColumn()->getId());
        // Since column name contains "Done" or "Terminé", the message completion should auto-sync to true
        static::assertTrue($message->isCompleted());
    }

    #[Test]
    public function testAssignMessage(): void
    {
        $this->client->request(
            'POST',
            sprintf('/messages/%d/assign', $this->testMessage->getId()),
            [
                'userId' => $this->testUser->getId(),
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertSame($this->testUser->getId(), $message->getAssignedTo()->getId());
    }

    #[Test]
    public function testSetDueDate(): void
    {
        $this->client->request(
            'POST',
            sprintf('/messages/%d/due-date', $this->testMessage->getId()),
            [
                'dueAt' => '2026-12-25',
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertSame('2026-12-25', $message->getDueAt()->format('Y-m-d'));
    }

    #[Test]
    public function testSetPriority(): void
    {
        $this->client->request(
            'POST',
            sprintf('/messages/%d/priority', $this->testMessage->getId()),
            [
                'priority' => 'high',
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertSame('high', $message->getPriority());
    }

    #[Test]
    public function testSetLabels(): void
    {
        $this->client->request(
            'POST',
            sprintf('/messages/%d/labels', $this->testMessage->getId()),
            [
                'labels' => 'bug, urgent, frontend',
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertSame(['bug', 'urgent', 'frontend'], $message->getLabels());
    }

    #[Test]
    public function testToggleComplete(): void
    {
        static::assertFalse($this->testMessage->isCompleted());

        $this->client->request('POST', sprintf('/messages/%d/kanban-complete', $this->testMessage->getId()));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $message = $this->entityManager->getRepository(Message::class)->find($this->testMessage->getId());
        static::assertTrue($message->isCompleted());
    }
}
