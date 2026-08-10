<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class EmailVerificationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $user = new User();
        $user->setUsername('test_email_user');
        $user->setEmail('test_email@example.com');
        $user->setRoles(['ROLE_USER']);
        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->user = $user;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['username' => 'test_email_user']);
        if ($user) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }
    }

    #[Test]
    public function testResendVerificationGetMethodNotAllowed(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/verify-email/resend');
        $this->assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function testResendVerificationPostSuccess(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('POST', '/verify-email/resend');

        $this->assertResponseRedirects('/account');
    }
}
