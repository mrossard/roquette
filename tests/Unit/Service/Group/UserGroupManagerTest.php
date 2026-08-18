<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Group;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\Workspace;
use App\Enum\AuditAction;
use App\Repository\UserGroupRepository;
use App\Repository\UserRepository;
use App\Service\AuditLoggerService;
use App\Service\Group\GroupProviderInterface;
use App\Service\Group\UserGroupManager;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class UserGroupManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UserGroupRepository $userGroupRepository;
    private UserRepository $userRepository;
    private WorkspaceManager $workspaceManager;
    private GroupProviderInterface $groupProvider;
    private AuditLoggerService $auditLogger;
    private TranslatorInterface $translator;
    private UserGroupManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userGroupRepository = $this->createMock(UserGroupRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->workspaceManager = $this->createMock(WorkspaceManager::class);
        $this->groupProvider = $this->createMock(GroupProviderInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerService::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->translator->method('trans')->willReturnCallback(static fn(string $id) => $id);

        $this->manager = new UserGroupManager(
            $this->entityManager,
            $this->userGroupRepository,
            $this->userRepository,
            $this->workspaceManager,
            $this->groupProvider,
            $this->auditLogger,
            $this->translator,
        );
    }

    public function testCreateLocalGroupSuccess(): void
    {
        $creator = new User();
        $creator->setUsername('alice');

        $workspace = new Workspace();
        $this->workspaceManager
            ->expects(static::once())
            ->method('create')
            ->with('Dev Team', 'Espace de travail officiel du groupe Dev Team', $creator)
            ->willReturn($workspace);

        $this->entityManager->expects(static::once())->method('persist')->with(static::isInstanceOf(UserGroup::class));
        $this->entityManager->expects(static::once())->method('flush');

        $this->auditLogger
            ->expects(static::once())
            ->method('log')
            ->with(AuditAction::GROUP_CREATE, $creator, static::callback(static fn(array $context): bool => $context['group_name'] === 'Dev Team' && str_starts_with($context['group_identifier'], 'local-group-')));

        $group = $this->manager->createLocalGroup('Dev Team', $creator);

        static::assertSame('Dev Team', $group->getName());
        static::assertSame($workspace, $group->getWorkspace());
        static::assertTrue($group->getAdministrators()->contains($creator));
    }

    public function testCreateLocalGroupEmptyNameThrows(): void
    {
        $creator = new User();
        $this->expectException(InvalidArgumentException::class);

        $this->manager->createLocalGroup('   ', $creator);
    }

    public function testImportGroupSuccess(): void
    {
        $creator = new User();
        $creator->setUsername('alice');

        $this->userGroupRepository->expects(static::once())->method('findOneBy')->with(['groupIdentifier' => 'ldap-devs'])->willReturn(null);

        $workspace = new Workspace();
        $this->workspaceManager
            ->expects(static::once())
            ->method('create')
            ->with('LDAP Devs', 'Espace de travail officiel du groupe LDAP Devs', $creator)
            ->willReturn($workspace);

        $this->entityManager->expects(static::once())->method('persist');
        $this->entityManager->expects(static::once())->method('flush');

        $this->auditLogger
            ->expects(static::once())
            ->method('log')
            ->with(AuditAction::GROUP_CREATE, $creator, static::callback(static fn(array $context): bool => $context['group_name'] === 'LDAP Devs'
                    && $context['group_identifier'] === 'ldap-devs'
                    && ($context['imported'] ?? false) === true));

        $group = $this->manager->importGroup('ldap-devs', 'LDAP Devs', $creator);

        static::assertSame('LDAP Devs', $group->getName());
        static::assertSame('ldap-devs', $group->getGroupIdentifier());
    }

    public function testImportGroupAlreadyExistsThrows(): void
    {
        $creator = new User();
        $existing = new UserGroup();
        $this->userGroupRepository->expects(static::once())->method('findOneBy')->with(['groupIdentifier' => 'ldap-devs'])->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        $this->manager->importGroup('ldap-devs', 'LDAP Devs', $creator);
    }

    public function testDeleteGroupRemovesWorkspaceAndGroup(): void
    {
        $user = new User();
        $group = new UserGroup();
        $group->setName('Marketing');
        $group->setGroupIdentifier('local-group-123');

        $workspace = new Workspace();
        $group->setWorkspace($workspace);

        $this->entityManager->expects(static::exactly(2))->method('remove');
        $this->entityManager->expects(static::once())->method('flush');

        $this->auditLogger
            ->expects(static::once())
            ->method('log')
            ->with(AuditAction::GROUP_DELETE, $user, static::callback(static fn(array $context): bool => $context['group_name'] === 'Marketing' && $context['group_identifier'] === 'local-group-123'));

        $this->manager->deleteGroup($group, $user);
    }

    public function testRemoveLastAdministratorThrows(): void
    {
        $admin = new User();
        $group = new UserGroup();
        $group->addAdministrator($admin);

        $this->expectException(InvalidArgumentException::class);
        $this->manager->removeAdministrator($group, $admin);
    }

    public function testRemoveAdministratorSuccessWhenMultiple(): void
    {
        $admin1 = new User();
        $admin2 = new User();
        $group = new UserGroup();
        $group->addAdministrator($admin1);
        $group->addAdministrator($admin2);

        $this->entityManager->expects(static::once())->method('flush');

        $this->manager->removeAdministrator($group, $admin1);

        static::assertFalse($group->getAdministrators()->contains($admin1));
        static::assertTrue($group->getAdministrators()->contains($admin2));
    }
}
