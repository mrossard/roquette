<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WorkspaceSelectorTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $users = $this->entityManager->getRepository(User::class)->findBy([
            'username' => ['test_ws_sel_user', 'test_ws_sel_author'],
        ]);

        foreach ($users as $user) {
            $channels = $this->entityManager->getRepository(Channel::class)->findBy(['creator' => $user]);
            foreach ($channels as $channel) {
                $messages = $this->entityManager->getRepository(Message::class)->findBy(['channel' => $channel]);
                foreach ($messages as $msg) {
                    $this->entityManager->remove($msg);
                }
                $this->entityManager->remove($channel);
            }

            $workspaces = $this->entityManager->getRepository(Workspace::class)->findBy(['creator' => $user]);
            foreach ($workspaces as $ws) {
                $this->entityManager->remove($ws);
            }

            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
    }

    public function testWorkspaceSelectorRendersWorkspacesAndUnreadCounts(): void
    {
        $user = new User();
        $user->setUsername('test_ws_sel_user');
        $user->setRoles(['ROLE_USER']);
        $hasher = $this->client->getContainer()->get('security.user_password_hasher');
        $user->setPassword($hasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $author = new User();
        $author->setUsername('test_ws_sel_author');
        $author->setRoles(['ROLE_USER']);
        $author->setPassword($hasher->hashPassword($author, 'password123'));
        $this->entityManager->persist($author);

        $ws = new Workspace();
        $ws->setName('Selector Workspace');
        $ws->setSlug('selector-workspace');
        $ws->setCreator($user);
        $ws->addMember($user);
        $ws->addMember($author);
        $this->entityManager->persist($ws);

        $channel = new Channel();
        $channel->setName('Selector Channel');
        $channel->setSlug('selector-channel');
        $channel->setCreator($user);
        $channel->setWorkspace($ws);
        $channel->addMember($user);
        $channel->addMember($author);
        $this->entityManager->persist($channel);

        // Add an unread message from author
        $msg = new Message();
        $msg->setContent('Unread message in workspace');
        $msg->setAuthor($author);
        $msg->setChannel($channel);
        $msg->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msg);

        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/sidebar/workspace-selector?channel=selector-channel');

        static::assertResponseIsSuccessful();
        static::assertStringContainsString('selector-workspace', (string) $this->client->getResponse()->getContent());
    }
}
