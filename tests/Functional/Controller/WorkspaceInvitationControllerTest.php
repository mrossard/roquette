<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WorkspaceInvitationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $owner;
    private User $invitee;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        $owner = new User();
        $owner->setUsername('ws_owner_test');
        $owner->setRoles(['ROLE_USER']);
        $owner->setPassword($passwordHasher->hashPassword($owner, 'password123'));
        $this->entityManager->persist($owner);

        $invitee = new User();
        $invitee->setUsername('ws_invitee_test');
        $invitee->setRoles(['ROLE_USER']);
        $invitee->setPassword($passwordHasher->hashPassword($invitee, 'password123'));
        $this->entityManager->persist($invitee);

        $workspace = new Workspace();
        $workspace->setName('Test Invite Workspace');
        $workspace->setSlug('test-invite-workspace');
        $workspace->setCreator($owner);
        $workspace->addMember($owner);
        $this->entityManager->persist($workspace);

        $this->entityManager->flush();

        $this->owner = $owner;
        $this->invitee = $invitee;
        $this->workspace = $workspace;
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

        $ws = $wsRepo->findOneBy(['slug' => 'test-invite-workspace']);
        if ($ws) {
            $this->entityManager->remove($ws);
        }

        foreach (['ws_owner_test', 'ws_invitee_test'] as $username) {
            $u = $userRepo->findOneBy(['username' => $username]);
            if ($u) {
                $this->entityManager->remove($u);
            }
        }

        $this->entityManager->flush();
    }

    public function testInviteModalRequiresAuthenticationAndPermission(): void
    {
        // Unauthenticated
        $this->client->request('GET', '/workspaces/test-invite-workspace/invite-modal');
        $this->assertResponseRedirects('/login');

        // Authenticated as invitee (not a member/admin of this private workspace)
        $this->client->loginUser($this->invitee);
        $this->client->request('GET', '/workspaces/test-invite-workspace/invite-modal');
        $this->assertResponseStatusCodeSame(403);

        // Authenticated as owner
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/workspaces/test-invite-workspace/invite-modal');
        $this->assertResponseIsSuccessful();
    }

    public function testSearchInvitableUsers(): void
    {
        $this->client->loginUser($this->owner);

        // Empty search
        $this->client->request('GET', '/workspaces/test-invite-workspace/invite/search?q=');
        $this->assertResponseIsSuccessful();

        // Search for invitee
        $this->client->request('GET', '/workspaces/test-invite-workspace/invite/search?q=ws_invitee');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ws_invitee_test');
    }

    public function testInviteUserSuccess(): void
    {
        $this->client->loginUser($this->owner);

        $this->client->request('POST', '/workspaces/test-invite-workspace/invite', [
            'userId' => $this->invitee->getId(),
            'q' => 'ws_invitee',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ws_invitee_test a été invité !');
    }
}
