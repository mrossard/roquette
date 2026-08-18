<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class ModalControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $member;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->entityManager = $this->client->getContainer()->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $this->client->getContainer()->get('security.user_password_hasher');

        $member = new User();
        $member->setUsername('modal_member');
        $member->setRoles(['ROLE_USER']);
        $member->setPassword($passwordHasher->hashPassword($member, 'password123'));
        $this->entityManager->persist($member);

        $outsider = new User();
        $outsider->setUsername('modal_outsider');
        $outsider->setRoles(['ROLE_USER']);
        $outsider->setPassword($passwordHasher->hashPassword($outsider, 'password123'));
        $this->entityManager->persist($outsider);

        $privateChannel = new Channel();
        $privateChannel->setName('Modal Private Channel');
        $privateChannel->setSlug('modal-private-channel');
        $privateChannel->setIsPrivate(true);
        $privateChannel->setCreator($member);
        $privateChannel->addMember($member);
        $this->entityManager->persist($privateChannel);

        $publicChannel = new Channel();
        $publicChannel->setName('Modal Public Channel');
        $publicChannel->setSlug('modal-public-channel');
        $publicChannel->setIsPrivate(false);
        $publicChannel->setCreator($member);
        $this->entityManager->persist($publicChannel);

        $admin = new User();
        $admin->setUsername('modal_admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'password123'));
        $this->entityManager->persist($admin);

        $noCreatorChannel = new Channel();
        $noCreatorChannel->setName('Modal No Creator Channel');
        $noCreatorChannel->setSlug('modal-no-creator-channel');
        $noCreatorChannel->setIsPrivate(false);
        $this->entityManager->persist($noCreatorChannel);

        $this->entityManager->flush();

        $this->member = $member;
        $this->outsider = $outsider;
        $this->admin = $admin;
    }

    private User $admin;

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $channelRepository = $this->entityManager->getRepository(Channel::class);

        $channels = $channelRepository->findBy(['slug' => ['modal-private-channel', 'modal-public-channel', 'modal-no-creator-channel']]);
        foreach ($channels as $c) {
            $this->entityManager->remove($c);
        }

        $users = $userRepository->findBy(['username' => ['modal_member', 'modal_outsider', 'modal_admin']]);
        foreach ($users as $u) {
            $this->entityManager->remove($u);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testMembersModalForbiddenForNonMemberOfPrivateChannel(): void
    {
        $this->client->loginUser($this->outsider);

        $this->client->request('GET', '/channels/modal-private-channel/members-modal');

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testMembersModalAllowedForMemberOfPrivateChannel(): void
    {
        $this->client->loginUser($this->member);

        $this->client->request('GET', '/channels/modal-private-channel/members-modal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('*', 'modal_member');
    }

    #[Test]
    public function testMembersModalAllowedOnPublicChannel(): void
    {
        $this->client->loginUser($this->outsider);

        $this->client->request('GET', '/channels/modal-public-channel/members-modal');

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testEditModalRendersForCreator(): void
    {
        $this->client->loginUser($this->member);

        $this->client->request('GET', '/channels/modal-public-channel/edit-modal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#edit-channel-modal');
    }

    #[Test]
    public function testEditModalRendersForAdminWhenChannelHasNoCreator(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/channels/modal-no-creator-channel/edit-modal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#edit-channel-modal');
    }
}
