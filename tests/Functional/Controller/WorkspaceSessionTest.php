<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WorkspaceSessionTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Workspace $workspaceA;
    private Workspace $workspaceB;
    private Channel $channelA;
    private Channel $channelB;
    private Channel $dmChannel;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        // 1. Create a test user
        $user = new User();
        $user->setUsername('test_ws_session_user');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        // 2. Create Workspace A
        $wsA = new Workspace();
        $wsA->setName('Workspace A');
        $wsA->setSlug('workspace-a');
        $wsA->setCreator($user);
        $wsA->addMember($user);
        $this->entityManager->persist($wsA);

        // 3. Create Workspace B
        $wsB = new Workspace();
        $wsB->setName('Workspace B');
        $wsB->setSlug('workspace-b');
        $wsB->setCreator($user);
        $wsB->addMember($user);
        $this->entityManager->persist($wsB);

        // 4. Create channels
        $chA = new Channel();
        $chA->setName('Channel A');
        $chA->setSlug('channel-a');
        $chA->setWorkspace($wsA);
        $chA->setCreator($user);
        $chA->addMember($user);
        $this->entityManager->persist($chA);

        $chB = new Channel();
        $chB->setName('Channel B');
        $chB->setSlug('channel-b');
        $chB->setWorkspace($wsB);
        $chB->setCreator($user);
        $chB->addMember($user);
        $this->entityManager->persist($chB);

        // 5. Create DM channel
        $dm = new Channel();
        $dm->setName('DM Channel');
        $dm->setSlug('dm-test-channel');
        $dm->setIsDm(true);
        $dm->setCreator($user);
        $dm->addMember($user);
        $this->entityManager->persist($dm);

        $this->entityManager->flush();

        $this->testUser = $user;
        $this->workspaceA = $wsA;
        $this->workspaceB = $wsB;
        $this->channelA = $chA;
        $this->channelB = $chB;
        $this->dmChannel = $dm;

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepo = $this->entityManager->getRepository(User::class);
        $wsRepo = $this->entityManager->getRepository(Workspace::class);
        $chRepo = $this->entityManager->getRepository(Channel::class);

        $user = $userRepo->findOneBy(['username' => 'test_ws_session_user']);
        $wsA = $wsRepo->findOneBy(['slug' => 'workspace-a']);
        $wsB = $wsRepo->findOneBy(['slug' => 'workspace-b']);
        $chA = $chRepo->findOneBy(['slug' => 'channel-a']);
        $chB = $chRepo->findOneBy(['slug' => 'channel-b']);
        $dm = $chRepo->findOneBy(['slug' => 'dm-test-channel']);

        if ($chA) {
            $this->entityManager->remove($chA);
        }
        if ($chB) {
            $this->entityManager->remove($chB);
        }
        if ($dm) {
            $this->entityManager->remove($dm);
        }
        if ($wsA) {
            $this->entityManager->remove($wsA);
        }
        if ($wsB) {
            $this->entityManager->remove($wsB);
        }
        if ($user) {
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
    }

    public function testWorkspaceSessionRetention(): void
    {
        // 1. Visit channel A in Workspace A -> Session should remember Workspace A
        $this->client->request('GET', '/channels/channel-a');
        $this->assertResponseIsSuccessful();
        $this->assertEquals(
            $this->workspaceA->getId(),
            $this->client->getRequest()->getSession()->get('current_workspace_id'),
        );

        // 2. Visit DM channel (no workspace) -> Session should still keep Workspace A
        $this->client->request('GET', '/channels/dm-test-channel');
        $this->assertResponseIsSuccessful();
        $this->assertEquals(
            $this->workspaceA->getId(),
            $this->client->getRequest()->getSession()->get('current_workspace_id'),
        );

        // 3. Switch workspace to Workspace B -> Session should update to Workspace B
        $this->client->request('GET', '/w/workspace-b');
        $this->assertResponseRedirects('/channels/channel-b');
        $this->client->followRedirect();
        $this->assertEquals(
            $this->workspaceB->getId(),
            $this->client->getRequest()->getSession()->get('current_workspace_id'),
        );

        // 4. Visit DM channel again -> Session should still keep Workspace B
        $this->client->request('GET', '/channels/dm-test-channel');
        $this->assertResponseIsSuccessful();
        $this->assertEquals(
            $this->workspaceB->getId(),
            $this->client->getRequest()->getSession()->get('current_workspace_id'),
        );
    }
}
