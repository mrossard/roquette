<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\RobotUserProvider;
use App\Service\UniqueSlugGenerator;
use App\Service\UserBootstrapService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class UserBootstrapServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private RequestStack $requestStack;
    private TranslatorInterface $translator;
    private UniqueSlugGenerator $slugGenerator;
    private Session $session;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createStub(UserPasswordHasherInterface::class);
        $this->requestStack = new RequestStack();
        $this->session = new Session(new MockArraySessionStorage());
        $this->session->start();
        $request = new Request();
        $request->setSession($this->session);
        $this->requestStack->push($request);

        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnArgument(0);
        $this->slugGenerator = new UniqueSlugGenerator(new \Symfony\Component\String\Slugger\AsciiSlugger());
    }

    #[Test]
    public function bootstrapDoesNothingIfUserIdIsNull(): void
    {
        $user = new User();
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $service = new UserBootstrapService(
            $this->entityManager,
            $this->passwordHasher,
            $this->requestStack,
            $this->translator,
            $this->slugGenerator,
            $this->createMock(RobotUserProvider::class),
        );

        $service->bootstrap($user);
    }

    #[Test]
    public function bootstrapRunsWhenUserHasIdAndFlushes(): void
    {
        $user = new User();
        $user->setUsername('john');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 10);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $channelRepo = $this->createMock(ChannelRepository::class);
        $userRepo = $this->createMock(UserRepository::class);

        $publicWorkspace = new Workspace();
        $publicWorkspace->setIsPublic(true);

        $workspaceRepo->method('findOneBy')->willReturn($publicWorkspace);
        $channelRepo->method('findOneBy')->willReturn(null);
        $userRepo->method('findOneBy')->willReturn(null);

        $this->entityManager
            ->method('getRepository')
            ->willReturnCallback(static fn(string $class) => match ($class) {
                Workspace::class => $workspaceRepo,
                Channel::class => $channelRepo,
                User::class => $userRepo,
            });

        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $service = new UserBootstrapService(
            $this->entityManager,
            $this->passwordHasher,
            $this->requestStack,
            $this->translator,
            $this->slugGenerator,
            $this->createMock(RobotUserProvider::class),
        );

        $service->bootstrap($user);

        // Second run in same session should not flush again
        $service->bootstrap($user);
    }

    #[Test]
    public function bootstrapDoesNotFlushWhenAllEntitiesAlreadyConfigured(): void
    {
        $user = new User();
        $user->setUsername('jane');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 20);

        $robotUser = new User();
        $robotUser->setUsername(User::ROBOT_USERNAME);
        $robotUser->setDisplayName('channel.assistant.name');

        $publicWorkspace = new Workspace();
        $publicWorkspace->setIsPublic(true);
        $publicWorkspace->addMember($user);

        $generalChannel = new Channel();
        $generalChannel->setName('channel.general.name');
        $generalChannel->setDescription('channel.general.description');
        $generalChannel->setWorkspace($publicWorkspace);

        $robotChannel = new Channel();
        $robotChannel->setName('channel.assistant.name');
        $robotChannel->setDescription('channel.assistant.description');
        $robotChannel->addMember($user);
        $robotChannel->addMember($robotUser);

        $workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $workspaceRepo->method('findOneBy')->willReturn($publicWorkspace);

        $channelRepo = $this->createMock(ChannelRepository::class);
        $channelRepo
            ->method('findOneBy')
            ->willReturnCallback(static function (array $criteria) use ($generalChannel, $robotChannel) {
                if (($criteria['workspace'] ?? null) !== null) {
                    return $generalChannel;
                }
                return $robotChannel;
            });

        $robotUserProvider = $this->createMock(RobotUserProvider::class);
        $robotUserProvider->method('getRobotUser')->willReturn($robotUser);
        $robotUserProvider->method('getDmChannelSlug')->willReturn('dm-robot-20');

        $this->entityManager
            ->method('getRepository')
            ->willReturnCallback(static fn(string $class) => match ($class) {
                Workspace::class => $workspaceRepo,
                Channel::class => $channelRepo,
                User::class => $this->createMock(UserRepository::class),
            });

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $service = new UserBootstrapService(
            $this->entityManager,
            $this->passwordHasher,
            $this->requestStack,
            $this->translator,
            $this->slugGenerator,
            $robotUserProvider,
        );

        $service->bootstrap($user);
    }
}
