<?php

namespace App\Tests\Functional\Command;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeExpiredMessagesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Channel $channel;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        // Create test user
        $user = new User();
        $user->setUsername('test_purge_user');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        // Create test channel with 1 month retention
        $channel = new Channel();
        $channel->setName('Purge Test Channel');
        $channel->setSlug('purge-test-channel');
        $channel->setMessageRetentionMonths(1);
        $channel->setCreator($user);
        $channel->addMember($user);
        $this->entityManager->persist($channel);

        $this->entityManager->flush();

        $this->user = $user;
        $this->channel = $channel;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['username' => 'test_purge_user']);
        if ($user) {
            $user->getSavedMessages()->clear();
            $this->entityManager->flush();
        }

        $channelRepo = $this->entityManager->getRepository(Channel::class);
        $channel = $channelRepo->findOneBy(['slug' => 'purge-test-channel']);
        if ($channel) {
            $messageRepo = $this->entityManager->getRepository(Message::class);
            $messages = $messageRepo->findBy(['channel' => $channel]);
            foreach ($messages as $msg) {
                $this->entityManager->remove($msg);
            }
            $this->entityManager->remove($channel);
        }

        if ($user) {
            $this->entityManager->remove($user);
        }

        $this->entityManager->flush();
    }

    public function testPurgeCommandExcludesSavedMessages(): void
    {
        $twoMonthsAgo = (new \DateTimeImmutable())->modify('-2 months');

        // Message A: Old, not saved (should be purged)
        $msgA = new Message();
        $msgA->setChannel($this->channel);
        $msgA->setAuthor($this->user);
        $msgA->setContent('Message A (non-sauvegardé et ancien)');
        $msgA->setCreatedAt($twoMonthsAgo);
        $this->entityManager->persist($msgA);

        // Message B: Old, saved by user (should NOT be purged)
        $msgB = new Message();
        $msgB->setChannel($this->channel);
        $msgB->setAuthor($this->user);
        $msgB->setContent('Message B (sauvegardé et ancien)');
        $msgB->setCreatedAt($twoMonthsAgo);
        $this->entityManager->persist($msgB);

        // Message C: New, not saved (should NOT be purged)
        $msgC = new Message();
        $msgC->setChannel($this->channel);
        $msgC->setAuthor($this->user);
        $msgC->setContent('Message C (récent)');
        $msgC->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msgC);

        // Add Message B to user's saved messages
        $this->user->addSavedMessage($msgB);

        $this->entityManager->flush();

        $idA = $msgA->getId();
        $idB = $msgB->getId();
        $idC = $msgC->getId();

        // Run the purge command
        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:purge-expired-messages');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Message A', $output);
        $this->assertStringNotContainsString('Message B', $output);
        $this->assertStringNotContainsString('Message C', $output);

        // Assert Message A is deleted, but B and C remain in database
        $this->entityManager->clear();
        $messageRepo = $this->entityManager->getRepository(Message::class);

        $this->assertNull($messageRepo->find($idA));
        $this->assertNotNull($messageRepo->find($idB));
        $this->assertNotNull($messageRepo->find($idC));
    }
}
