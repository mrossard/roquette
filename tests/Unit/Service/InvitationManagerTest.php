<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Invitation;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use App\Service\InvitationManager;
use App\Service\MercurePublisher;
use App\Service\WorkspaceManager;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class InvitationManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private WorkspaceRepository $workspaceRepository;
    private MercurePublisher $mercurePublisher;
    private WorkspaceManager $workspaceManager;
    private Environment $twig;
    private \Psr\Log\LoggerInterface $logger;
    private TranslatorInterface $translator;
    private InvitationManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->workspaceRepository = $this->createMock(WorkspaceRepository::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->workspaceManager = $this->createMock(WorkspaceManager::class);
        $this->twig = $this->createMock(Environment::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->translator->method('trans')->willReturnCallback(static fn(string $id) => $id);

        $this->manager = new InvitationManager(
            $this->entityManager,
            $this->userRepository,
            $this->workspaceRepository,
            $this->mercurePublisher,
            $this->workspaceManager,
            $this->twig,
            $this->logger,
            $this->translator,
        );
    }

    public function testInviteToDmChannelThrows(): void
    {
        $channel = new Channel();
        $channel->setIsDm(true);

        $inviter = new User();
        $invitee = new User();

        $this->expectException(InvalidArgumentException::class);
        $this->manager->inviteToChannel($channel, $inviter, $invitee);
    }

    public function testInviteToChannelSuccess(): void
    {
        $channel = new Channel();
        $channel->setName('Projet Alpha');
        $channel->setSlug('projet-alpha');
        $channel->setIsDm(false);

        $inviter = new User();
        $inviter->setUsername('alice');
        $inviter->setDisplayName('Alice Wonder');

        $invitee = new User();
        $invitee->setUsername('bob');

        $this->entityManager->expects(static::once())->method('persist')->with(static::isInstanceOf(Invitation::class));
        $this->entityManager->expects(static::once())->method('flush');

        $this->twig
            ->expects(static::once())
            ->method('render')
            ->with('dashboard/_invite_sidebar_item.html.twig', static::isArray())
            ->willReturn('<div>Invite</div>');

        $this->mercurePublisher
            ->expects(static::once())
            ->method('publishToUser')
            ->with($invitee, static::callback(static fn(array $data) => $data['type'] === 'invitation_received'
                && $data['invitedUsername'] === 'bob'
                && $data['channelSlug'] === 'projet-alpha'
                && $data['senderName'] === 'Alice Wonder'
                && $data['html'] === '<div>Invite</div>'), 'invitation_received');

        $invitation = $this->manager->inviteToChannel($channel, $inviter, $invitee);

        static::assertSame($channel, $invitation->getChannel());
        static::assertSame($invitee, $invitation->getInvitee());
    }

    public function testAcceptChannelInvitationSuccess(): void
    {
        $user = new User();
        $channel = new Channel();
        $channel->setName('Dev');
        $channel->setSlug('dev');

        $invitation = new Invitation();
        $invitation->setInvitee($user);
        $invitation->setChannel($channel);

        $this->entityManager->expects(static::once())->method('remove')->with($invitation);
        $this->entityManager->expects(static::once())->method('flush');

        $result = $this->manager->acceptInvitation($invitation, $user);

        static::assertSame(['type' => 'channel', 'slug' => 'dev'], $result);
        static::assertTrue($channel->getMembers()->contains($user));
    }

    public function testAcceptWorkspaceInvitationWithDefaultChannel(): void
    {
        $user = new User();
        $workspace = new Workspace();
        $workspace->setName('Acme');
        $workspace->setSlug('acme');

        $defaultChannel = new Channel();
        $defaultChannel->setSlug('general');

        $invitation = new Invitation();
        $invitation->setInvitee($user);
        $invitation->setWorkspace($workspace);

        $this->workspaceManager
            ->expects(static::once())
            ->method('acceptInvitation')
            ->with($invitation, $user);

        $this->workspaceManager
            ->expects(static::once())
            ->method('getDefaultChannel')
            ->with($workspace)
            ->willReturn($defaultChannel);

        $result = $this->manager->acceptInvitation($invitation, $user);

        static::assertSame(['type' => 'channel', 'slug' => 'general'], $result);
    }

    public function testAcceptInvitationForbiddenForAnotherUser(): void
    {
        $user1 = new User();
        $user2 = new User();

        $invitation = new Invitation();
        $invitation->setInvitee($user1);

        $this->expectException(AccessDeniedHttpException::class);
        $this->manager->acceptInvitation($invitation, $user2);
    }

    public function testRejectChannelInvitation(): void
    {
        $user = new User();
        $channel = new Channel();
        $channel->setName('Design');
        $channel->setSlug('design');

        $invitation = new Invitation();
        $invitation->setInvitee($user);
        $invitation->setChannel($channel);

        $this->entityManager->expects(static::once())->method('remove')->with($invitation);
        $this->entityManager->expects(static::once())->method('flush');

        $this->manager->rejectInvitation($invitation, $user);
    }

    public function testRejectWorkspaceInvitation(): void
    {
        $user = new User();
        $workspace = new Workspace();
        $workspace->setName('Acme');
        $workspace->setSlug('acme');

        $invitation = new Invitation();
        $invitation->setInvitee($user);
        $invitation->setWorkspace($workspace);

        $this->workspaceManager
            ->expects(static::once())
            ->method('rejectInvitation')
            ->with($invitation);

        $this->manager->rejectInvitation($invitation, $user);
    }
}
