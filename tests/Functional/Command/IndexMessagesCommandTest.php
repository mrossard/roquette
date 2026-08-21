<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class IndexMessagesCommandTest extends KernelTestCase
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

        $user = new User();
        $user->setUsername('index_cmd_user');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $channel = new Channel();
        $channel->setName('Index Command Channel');
        $channel->setSlug('index-command-channel');
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
        $channelRepo = $this->entityManager->getRepository(Channel::class);
        $messageRepo = $this->entityManager->getRepository(Message::class);
        $userRepo = $this->entityManager->getRepository(User::class);

        $ch = $channelRepo->findOneBy(['slug' => 'index-command-channel']);
        if ($ch) {
            $messages = $messageRepo->findBy(['channel' => $ch]);
            foreach ($messages as $msg) {
                $this->entityManager->remove($msg);
            }
            $this->entityManager->remove($ch);
        }

        $u = $userRepo->findOneBy(['username' => 'index_cmd_user']);
        if ($u) {
            $this->entityManager->remove($u);
        }

        $this->entityManager->flush();
    }

    public function testIndexMessagesCommandRunsSuccessfully(): void
    {
        $msg = new Message();
        $msg->setChannel($this->channel);
        $msg->setAuthor($this->user);
        $msg->setContent('Message pour test index command');
        $msg->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($msg);
        $this->entityManager->flush();

        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:ai:index-messages');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);
        static::assertSame(0, $commandTester->getStatusCode());

        $output = $commandTester->getDisplay();
        static::assertStringContainsString('Indexation vectorielle des messages', $output);
    }
}
