<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class InvitationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $creator;
    private User $invitee;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        // Create Creator
        $this->creator = new User();
        $this->creator->setUsername('test_invite_creator');
        $this->creator->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $this->creator->setPassword($passwordHasher->hashPassword($this->creator, 'password123'));
        $this->entityManager->persist($this->creator);

        // Create Invitee
        $this->invitee = new User();
        $this->invitee->setUsername('test_invite_invitee');
        $this->invitee->setRoles(['ROLE_USER']);
        $this->invitee->setPassword($passwordHasher->hashPassword($this->invitee, 'password123'));
        $this->entityManager->persist($this->invitee);

        // Create Channel owned by Creator
        $this->channel = new Channel();
        $this->channel->setName('Test Invite Channel');
        $this->channel->setSlug('test-invite-channel');
        $this->channel->setCreator($this->creator);
        $this->channel->addMember($this->creator);
        $this->entityManager->persist($this->channel);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $users = $userRepository->findBy(['username' => [
            'test_invite_creator',
            'test_invite_invitee',
            'test_invite_other',
        ]]);

        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $channels = $channelRepository->findBy(['slug' => ['test-invite-channel', 'test-invite-dm']]);

        $invitationRepository = $this->entityManager->getRepository(Invitation::class);

        foreach ($channels as $channel) {
            $invitations = $invitationRepository->findBy(['channel' => $channel]);
            foreach ($invitations as $inv) {
                $this->entityManager->remove($inv);
            }
            $this->entityManager->remove($channel);
        }

        foreach ($users as $user) {
            $invitations = $invitationRepository->findBy(['invitee' => $user]);
            foreach ($invitations as $inv) {
                $this->entityManager->remove($inv);
            }
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testInviteUserSuccess(): void
    {
        $this->client->loginUser($this->creator);

        $this->client->request('POST', '/channels/test-invite-channel/invite', [
            'userId' => $this->invitee->getId(),
        ]);

        $this->assertResponseIsSuccessful();
        static::assertStringContainsString(
            'test_invite_invitee a été invité !',
            $this->client->getResponse()->getContent(),
        );

        // Check if invitation was created in DB
        $this->entityManager->clear();
        $invitation = $this->entityManager
            ->getRepository(Invitation::class)
            ->findOneBy([
                'channel' => $this->channel,
                'invitee' => $this->invitee,
            ]);

        static::assertNotNull($invitation);
    }

    #[Test]
    public function testInviteUserNonCreator(): void
    {
        $this->client->loginUser($this->invitee);

        $this->client->request('POST', '/channels/test-invite-channel/invite', [
            'userId' => $this->creator->getId(),
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testInviteUserDmForbidden(): void
    {
        $dmChannel = new Channel();
        $dmChannel->setName('DM Channel');
        $dmChannel->setSlug('test-invite-dm');
        $dmChannel->setIsDm(true);
        $dmChannel->setCreator($this->creator);
        $dmChannel->addMember($this->creator);
        $this->entityManager->persist($dmChannel);
        $this->entityManager->flush();

        $this->client->loginUser($this->creator);
        $this->client->request('POST', '/channels/test-invite-dm/invite', [
            'userId' => $this->invitee->getId(),
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testAcceptInvitationSuccess(): void
    {
        $invitation = new Invitation();
        $invitation->setChannel($this->channel);
        $invitation->setInvitee($this->invitee);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $invitationId = $invitation->getId();

        $this->client->loginUser($this->invitee);
        $this->client->request('POST', sprintf('/invitations/%d/accept', $invitationId));

        $this->assertResponseStatusCodeSame(204);
        $this->assertResponseHasHeader('HX-Redirect');

        $this->entityManager->clear();

        $dbChannel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $members = $dbChannel->getMembers();
        $memberUsernames = [];
        foreach ($members as $member) {
            $memberUsernames[] = $member->getUsername();
        }
        static::assertContains('test_invite_invitee', $memberUsernames);

        $dbInvitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);
        static::assertNull($dbInvitation);
    }

    #[Test]
    public function testRejectInvitationSuccess(): void
    {
        $invitation = new Invitation();
        $invitation->setChannel($this->channel);
        $invitation->setInvitee($this->invitee);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $invitationId = $invitation->getId();

        $this->client->loginUser($this->invitee);
        $this->client->request('POST', sprintf('/invitations/%d/reject', $invitationId));

        $this->assertResponseStatusCodeSame(200);

        $this->entityManager->clear();

        $dbInvitation = $this->entityManager->getRepository(Invitation::class)->find($invitationId);
        static::assertNull($dbInvitation);

        $dbChannel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $members = $dbChannel->getMembers();
        $memberUsernames = [];
        foreach ($members as $member) {
            $memberUsernames[] = $member->getUsername();
        }
        static::assertNotContains('test_invite_invitee', $memberUsernames);
    }

    #[Test]
    public function testAcceptInvitationUnauthorized(): void
    {
        $otherUser = new User();
        $otherUser->setUsername('test_invite_other');
        $otherUser->setRoles(['ROLE_USER']);
        $container = $this->client->getContainer();
        $passwordHasher = $container->get('security.user_password_hasher');
        $otherUser->setPassword($passwordHasher->hashPassword($otherUser, 'password123'));
        $this->entityManager->persist($otherUser);

        $invitation = new Invitation();
        $invitation->setChannel($this->channel);
        $invitation->setInvitee($this->invitee);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        $invitationId = $invitation->getId();

        $this->client->loginUser($otherUser);
        $this->client->request('POST', sprintf('/invitations/%d/accept', $invitationId));

        $this->assertResponseStatusCodeSame(403);
    }
}
