<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class GroupChannelSubscriptionTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $memberUser;
    private User $nonMemberUser;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        // 'manu' is in 'group-dev' in our InMemoryGroupProvider
        $user1 = new User();
        $user1->setUsername('manu');
        $user1->setRoles(['ROLE_USER']);
        $user1->setPassword($passwordHasher->hashPassword($user1, 'password123'));
        $this->entityManager->persist($user1);

        // 'other_user' is NOT in 'group-dev'
        $user2 = new User();
        $user2->setUsername('other_user');
        $user2->setRoles(['ROLE_USER']);
        $user2->setPassword($passwordHasher->hashPassword($user2, 'password123'));
        $this->entityManager->persist($user2);

        $this->entityManager->flush();

        $this->memberUser = $user1;
        $this->nonMemberUser = $user2;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $subRepo = $this->entityManager->getRepository(GroupSubscription::class);
        foreach ($subRepo->findAll() as $sub) {
            $this->entityManager->remove($sub);
        }

        $channelRepo = $this->entityManager->getRepository(Channel::class);
        foreach ($channelRepo->findAll() as $channel) {
            // General channel can't be easily removed if other tests rely on it, but we can delete private test ones
            if ($channel->getSlug() !== 'general' && !str_starts_with($channel->getSlug(), 'dm-')) {
                $this->entityManager->remove($channel);
            }
        }

        $userRepo = $this->entityManager->getRepository(User::class);
        foreach ($userRepo->findAll() as $user) {
            if (in_array($user->getUsername(), ['manu', 'other_user'], true)) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testGroupSubscriptionAccess(): void
    {
        // 1. Create a private channel owned by 'manu' but do NOT add 'manu' as a member of it directly
        // We will add 'group-dev' as a subscribed group instead!
        $channel = new Channel();
        $channel->setName('Dev Channel');
        $channel->setSlug('dev-channel');
        $channel->setIsPrivate(true);
        // Note: do not add member directly

        $this->entityManager->persist($channel);

        $sub = new GroupSubscription();
        $sub->setGroupIdentifier('group-dev');
        $sub->setIsGroupChannel(true);
        $channel->addGroupSubscription($sub);

        $this->entityManager->persist($sub);
        $this->entityManager->flush();

        // 2. Log in as 'manu' (who is in 'group-dev') and try to access the channel page
        $this->client->loginUser($this->memberUser);
        $this->client->request('GET', '/channels/dev-channel');
        $this->assertResponseIsSuccessful();

        // 3. Log in as 'other_user' (who is NOT in 'group-dev') and try to access the channel page
        $this->client->loginUser($this->nonMemberUser);
        $this->client->followRedirects(true);
        $this->client->request('GET', '/channels/dev-channel');

        $this->assertSelectorTextContains('.alert-error', "Vous n'avez pas accès à ce canal privé.");
    }
}
