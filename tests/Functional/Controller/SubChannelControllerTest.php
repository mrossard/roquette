<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class SubChannelControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Channel $testChannel;
    private Message $testMessage;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $user = new User();
        $user->setUsername('test_subch_user');
        $user->setDisplayName('SubChannel User');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'pass123'));
        $this->entityManager->persist($user);

        $channel = new Channel();
        $channel->setName('Parent Channel');
        $channel->setSlug('parent-channel-subch');
        $channel->setIsPrivate(false);
        $channel->setIsDm(false);
        $publicWorkspace = $this->entityManager->getRepository(\App\Entity\Workspace::class)->findOneBy(['isPublic' => true]);
        if ($publicWorkspace) {
            $channel->setWorkspace($publicWorkspace);
            $publicWorkspace->addMember($user);
        }
        $channel->addMember($user);
        $this->entityManager->persist($channel);

        $message = new Message();
        $message->setAuthor($user);
        $message->setChannel($channel);
        $message->setContent('Thread discussion message content');
        $this->entityManager->persist($message);

        $this->entityManager->flush();

        $this->testUser = $user;
        $this->testChannel = $channel;
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
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM channel WHERE slug LIKE ? OR slug LIKE ?', ['sc-%', 'parent-channel-subch%']);
        $conn->executeStatement('DELETE FROM "user" WHERE username LIKE ?', ['test_subch_%']);
    }

    #[Test]
    public function testCreateSubChannel(): void
    {
        $this->client->request('POST', '/messages/' . $this->testMessage->getId() . '/sub-channel');
        $this->assertResponseRedirects();

        $createdSubChannel = $this->entityManager->getRepository(Channel::class)->findOneBy([
            'parentMessage' => $this->testMessage,
        ]);
        static::assertNotNull($createdSubChannel);
        static::assertStringStartsWith('sc-', $createdSubChannel->getSlug());
        static::assertSame('Thread discussion message content', $createdSubChannel->getName());
        static::assertFalse($createdSubChannel->isTodoList());
        if ($this->testChannel->getWorkspace()) {
            static::assertSame($this->testChannel->getWorkspace()->getId(), $createdSubChannel->getWorkspace()?->getId());
        }
    }

    #[Test]
    public function testCreateSubChannelTodo(): void
    {
        $this->client->request('POST', '/messages/' . $this->testMessage->getId() . '/sub-channel-todo');
        $this->assertResponseRedirects();

        $createdSubChannel = $this->entityManager->getRepository(Channel::class)->findOneBy([
            'parentMessage' => $this->testMessage,
        ]);
        static::assertNotNull($createdSubChannel);
        static::assertStringStartsWith('sc-', $createdSubChannel->getSlug());
        static::assertTrue($createdSubChannel->isTodoList());
        if ($this->testChannel->getWorkspace()) {
            static::assertSame($this->testChannel->getWorkspace()->getId(), $createdSubChannel->getWorkspace()?->getId());
        }

        // Verify that the todo sub-channel appears in the sidebar when loading the channel page
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $todoItem = $crawler->filter('#section-todos #sidebar-channel-' . $createdSubChannel->getSlug());
        static::assertGreaterThan(0, $todoItem->count(), 'Todo subchannel should be visible in #section-todos in the sidebar');
    }
}
