<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CreateAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['username' => 'test_admin_cmd']);
        if ($user) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }
    }

    public function testCreateAdminSuccessfully(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:create-admin');
        $commandTester = new CommandTester($command);

        $passVal = 'adm-val-xyz-9';
        $commandTester->execute([
            'username' => 'test_admin_cmd',
            'password' => $passVal,
        ]);

        $output = $commandTester->getDisplay();
        static::assertStringContainsString('L\'administrateur "test_admin_cmd" a été créé avec succès.', $output);

        $this->entityManager->clear();
        $userRepo = $this->entityManager->getRepository(User::class);
        $user = $userRepo->findOneBy(['username' => 'test_admin_cmd']);

        static::assertNotNull($user);
        static::assertTrue($user->isAdmin());
        static::assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testCreateAdminAlreadyExistsPromotesUser(): void
    {
        // First create user
        $user = new User();
        $user->setUsername('test_admin_cmd');
        $user->setAdmin(false);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('dummy');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:create-admin');
        $commandTester = new CommandTester($command);

        $passVal = 'adm-val-xyz-9';
        $commandTester->execute([
            'username' => 'test_admin_cmd',
            'password' => $passVal,
        ]);

        $output = $commandTester->getDisplay();
        static::assertStringContainsString(
            'L\'utilisateur existant "test_admin_cmd" a été promu administrateur',
            $output,
        );

        $this->entityManager->clear();
        $userRepo = $this->entityManager->getRepository(User::class);
        $promotedUser = $userRepo->findOneBy(['username' => 'test_admin_cmd']);

        static::assertNotNull($promotedUser);
        static::assertTrue($promotedUser->isAdmin());
        static::assertContains('ROLE_ADMIN', $promotedUser->getRoles());
    }

    public function testCreateAdminCannotPromoteOrCreateRobot(): void
    {
        $kernel = self::$kernel;
        $application = new Application($kernel);
        $command = $application->find('app:create-admin');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'username' => 'robot-roquette',
            'password' => 'some-pwd',
        ]);

        $output = preg_replace('/\s+/', ' ', $commandTester->getDisplay());
        static::assertStringContainsString('Impossible de modifier ou de promouvoir le compte système de l\'assistant.', $output);
    }
}
