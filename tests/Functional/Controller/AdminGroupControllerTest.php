<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\AuditLog;
use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class AdminGroupControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $adminUser;
    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        $admin = new User();
        $admin->setUsername('admin_user');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $admin->setAdmin(true);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'password123'));
        $this->entityManager->persist($admin);

        $user = new User();
        $user->setUsername('normal_user');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $this->entityManager->flush();

        $this->adminUser = $admin;
        $this->normalUser = $user;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $logRepo = $this->entityManager->getRepository(AuditLog::class);
        foreach ($logRepo->findAll() as $log) {
            $this->entityManager->remove($log);
        }

        $groupRepo = $this->entityManager->getRepository(UserGroup::class);
        foreach ($groupRepo->findAll() as $group) {
            $this->entityManager->remove($group);
        }

        $subRepo = $this->entityManager->getRepository(GroupSubscription::class);
        foreach ($subRepo->findAll() as $sub) {
            $this->entityManager->remove($sub);
        }

        $channelRepo = $this->entityManager->getRepository(Channel::class);
        foreach ($channelRepo->findAll() as $channel) {
            if ($channel->getSlug() !== 'general' && !str_starts_with($channel->getSlug(), 'dm-')) {
                $this->entityManager->remove($channel);
            }
        }

        $userRepo = $this->entityManager->getRepository(User::class);
        foreach ($userRepo->findAll() as $u) {
            if (in_array($u->getUsername(), ['admin_user', 'normal_user', 'third_user'], true)) {
                $this->entityManager->remove($u);
            }
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testAccessDeniedForNonAdmin(): void
    {
        $this->client->loginUser($this->normalUser);
        $this->client->request('GET', '/admin/groups');
        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testAdminCanCreateLocalGroupAndAddRemoveMembers(): void
    {
        $this->client->loginUser($this->adminUser);

        // 1. Get index page
        $crawler = $this->client->request('GET', '/admin/groups');
        $this->assertResponseIsSuccessful();

        // 2. Create local group
        $this->client->request('POST', '/admin/groups/create', [
            'name' => 'Marketing Team',
        ]);
        $this->assertResponseRedirects('/admin/groups');
        $this->client->followRedirect();

        // Verify group and channel were created in DB
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => 'Marketing Team']);
        static::assertNotNull($group);
        static::assertNotNull($group->getChannel());
        static::assertSame('Marketing Team', $group->getChannel()->getName());

        // Verify AuditLog was created for group creation
        $logRepo = $this->entityManager->getRepository(AuditLog::class);
        $logs = $logRepo->findBy(['action' => AuditAction::GROUP_CREATE]);
        static::assertCount(1, $logs);
        static::assertSame('Marketing Team', $logs[0]->getDetails()['group_name']);
        static::assertSame($group->getId(), $logs[0]->getDetails()['group_id']);

        // Verify group subscription was created
        $sub = $this->entityManager
            ->getRepository(GroupSubscription::class)
            ->findOneBy([
                'groupIdentifier' => $group->getGroupIdentifier(),
                'isGroupChannel' => true,
            ]);
        static::assertNotNull($sub);
        static::assertSame($group->getChannel()->getId(), $sub->getChannel()->getId());

        // 3. Manage members
        $this->client->request('GET', sprintf('/admin/groups/%d/members', $group->getId()));
        $this->assertResponseIsSuccessful();

        // Add member
        $this->client->request('POST', sprintf('/admin/groups/%d/members/add', $group->getId()), [
            'userId' => $this->normalUser->getId(),
        ]);
        $this->assertResponseRedirects(sprintf('/admin/groups/%d/members', $group->getId()));
        $this->client->followRedirect();

        // Verify member was added
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        static::assertCount(1, $group->getMembers());
        static::assertSame('normal_user', $group->getMembers()->first()->getUsername());

        // Check that 'normal_user' can now access the channel (they are member of group)
        $this->client->loginUser($this->normalUser);
        $this->client->request('GET', '/channels/' . $group->getChannel()->getSlug());
        $this->assertResponseIsSuccessful();

        // 4. Remove member
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', sprintf(
            '/admin/groups/%d/members/%d/remove',
            $group->getId(),
            $this->normalUser->getId(),
        ));
        $this->assertResponseRedirects(sprintf('/admin/groups/%d/members', $group->getId()));
        $this->client->followRedirect();

        // Verify member was removed
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        static::assertCount(0, $group->getMembers());
    }

    #[Test]
    public function testSearchAndImportProviderGroup(): void
    {
        $this->client->loginUser($this->adminUser);

        // Search provider groups (InMemoryGroupProvider has 'group-dev')
        $crawler = $this->client->request('GET', '/admin/groups', ['search' => 'dev']);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Développeurs');

        // Import group-dev
        $this->client->request('POST', '/admin/groups/import', [
            'identifier' => 'group-dev',
            'name' => 'Développeurs',
        ]);
        $this->assertResponseRedirects('/admin/groups');
        $this->client->followRedirect();

        // Verify group import in DB
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['groupIdentifier' => 'group-dev']);
        static::assertNotNull($group);
        static::assertSame('Développeurs', $group->getName());
        static::assertNotNull($group->getChannel());

        // Verify AuditLog was created for group import
        $logRepo = $this->entityManager->getRepository(AuditLog::class);
        $logs = $logRepo->findBy(['action' => AuditAction::GROUP_CREATE]);
        $found = false;
        foreach ($logs as $log) {
            if ($log->getDetails()['group_identifier'] !== 'group-dev') {
                continue;
            }
            $found = true;
            static::assertTrue($log->getDetails()['imported'] ?? false);
            static::assertSame('Développeurs', $log->getDetails()['group_name']);
        }
        static::assertTrue($found, 'Audit log for group import not found');
    }

    #[Test]
    public function testGroupAdminCanAccessAndManageMembers(): void
    {
        // 1. Create a group and channel
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/admin/groups/create', [
            'name' => 'Support Team',
        ]);
        $this->assertResponseRedirects('/admin/groups');

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => 'Support Team']);
        static::assertNotNull($group);

        // 2. Make normalUser an administrator of this group
        $user = $this->entityManager->getRepository(User::class)->find($this->normalUser->getId());
        $group->addAdministrator($user);
        $group->addMember($user);
        $this->entityManager->flush();

        // Check if group administrator is also channel administrator
        static::assertTrue($group->getChannel()->isAdministrator($user));

        // 3. Create a third user to add to the group
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $user3 = new User();
        $user3->setUsername('third_user');
        $user3->setRoles(['ROLE_USER']);
        $user3->setPassword($passwordHasher->hashPassword($user3, 'password123'));

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $this->entityManager->persist($user3);
        $this->entityManager->flush();

        // 4. Log in as normalUser (who is a group admin)
        $user = $this->entityManager->getRepository(User::class)->find($this->normalUser->getId());
        $this->client->loginUser($user);

        // Can access the group index, which displays the administered group
        $crawler = $this->client->request('GET', '/admin/groups');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Support Team');

        // Can access member management page
        $this->client->request('GET', sprintf('/admin/groups/%d/members', $group->getId()));
        $this->assertResponseIsSuccessful();

        // Can add user3 to the group
        $this->client->request('POST', sprintf('/admin/groups/%d/members/add', $group->getId()), [
            'userId' => $user3->getId(),
        ]);
        $this->assertResponseRedirects(sprintf('/admin/groups/%d/members', $group->getId()));
        $this->client->followRedirect();

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $user3 = $this->entityManager->getRepository(User::class)->find($user3->getId());
        static::assertTrue($group->getMembers()->contains($user3));

        // Can promote user3 to group administrator
        $this->client->request('POST', sprintf('/admin/groups/%d/administrators/add', $group->getId()), [
            'userId' => $user3->getId(),
        ]);
        $this->assertResponseRedirects(sprintf('/admin/groups/%d/members', $group->getId()));
        $this->client->followRedirect();

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $user3 = $this->entityManager->getRepository(User::class)->find($user3->getId());
        static::assertTrue($group->isAdministrator($user3));

        // Can demote user3 from group administrator
        $this->client->request('POST', sprintf(
            '/admin/groups/%d/administrators/%d/remove',
            $group->getId(),
            $user3->getId(),
        ));
        $this->assertResponseRedirects(sprintf('/admin/groups/%d/members', $group->getId()));
        $this->client->followRedirect();

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $user3 = $this->entityManager->getRepository(User::class)->find($user3->getId());
        static::assertFalse($group->isAdministrator($user3));

        // Can remove user3 from the group
        $this->client->request('POST', sprintf('/admin/groups/%d/members/%d/remove', $group->getId(), $user3->getId()));
        $this->assertResponseRedirects(sprintf('/admin/groups/%d/members', $group->getId()));
        $this->client->followRedirect();

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->find($group->getId());
        $user3 = $this->entityManager->getRepository(User::class)->find($user3->getId());
        static::assertFalse($group->getMembers()->contains($user3));

        // 5. Attempt to delete the group should be forbidden
        $this->client->request('POST', sprintf('/admin/groups/%d/delete', $group->getId()));
        $this->assertResponseStatusCodeSame(403);

        // Clean up third user
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $user3 = $this->entityManager->getRepository(User::class)->find($user3->getId());
        $this->entityManager->remove($user3);
        $this->entityManager->flush();
    }

    #[Test]
    public function testNonGroupAdminAccessDeniedToManageMembers(): void
    {
        // 1. Create a group
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/admin/groups/create', [
            'name' => 'IT Department',
        ]);

        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => 'IT Department']);
        static::assertNotNull($group);

        // 2. normalUser is NOT administrator of this group, so access to members is forbidden
        $user = $this->entityManager->getRepository(User::class)->find($this->normalUser->getId());
        $this->client->loginUser($user);
        $this->client->request('GET', sprintf('/admin/groups/%d/members', $group->getId()));
        $this->assertResponseStatusCodeSame(403);

        // Post requests are also forbidden
        $this->client->request('POST', sprintf('/admin/groups/%d/members/add', $group->getId()), [
            'userId' => $this->adminUser->getId(),
        ]);
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf(
            '/admin/groups/%d/members/%d/remove',
            $group->getId(),
            $this->adminUser->getId(),
        ));
        $this->assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/groups/%d/administrators/add', $group->getId()), [
            'userId' => $this->adminUser->getId(),
        ]);
        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testMemberAutocomplete(): void
    {
        $this->client->loginUser($this->adminUser);

        // 1. Create a group
        $this->client->request('POST', '/admin/groups/create', [
            'name' => 'IT Department',
        ]);
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => 'IT Department']);
        static::assertNotNull($group);

        // 2. Query member autocomplete with 'normal' (matches 'normal_user')
        $this->client->request('GET', sprintf('/admin/groups/%d/members/autocomplete', $group->getId()), [
            'search' => 'normal',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'normal_user');
    }

    #[Test]
    public function testAdminCanDeleteGroupAndLogsAudit(): void
    {
        $this->client->loginUser($this->adminUser);

        // Create a local group
        $this->client->request('POST', '/admin/groups/create', [
            'name' => 'Support to Delete',
        ]);
        $this->assertResponseRedirects('/admin/groups');
        $this->client->followRedirect();

        $group = $this->entityManager->getRepository(UserGroup::class)->findOneBy(['name' => 'Support to Delete']);
        static::assertNotNull($group);
        $groupId = $group->getId();

        // Delete the group
        $this->client->request('POST', sprintf('/admin/groups/%d/delete', $groupId));
        $this->assertResponseRedirects('/admin/groups');
        $this->client->followRedirect();

        // Verify group was deleted
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();
        $deletedGroup = $this->entityManager->getRepository(UserGroup::class)->find($groupId);
        static::assertNull($deletedGroup);

        // Verify AuditLog was created for group deletion
        $logRepo = $this->entityManager->getRepository(AuditLog::class);
        $logs = $logRepo->findBy(['action' => AuditAction::GROUP_DELETE]);
        static::assertCount(1, $logs);
        static::assertSame('Support to Delete', $logs[0]->getDetails()['group_name']);
        static::assertSame($groupId, $logs[0]->getDetails()['group_id']);
    }
}
