<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
final class AdminMetricsControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $adminUser;
    private User $normalUser;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        $admin = new User();
        $admin->setUsername('metrics_admin');
        $admin->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
        $admin->setAdmin(true);
        $admin->setPassword($passwordHasher->hashPassword($admin, 'password123'));
        $this->entityManager->persist($admin);

        $user = new User();
        $user->setUsername('metrics_user');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $ws = new Workspace();
        $ws->setName('Metrics Test Workspace');
        $ws->setSlug('metrics-test-ws');
        $ws->setCreator($admin);
        $this->entityManager->persist($ws);

        $channel = new Channel();
        $channel->setName('general-metrics');
        $channel->setSlug('general-metrics');
        $channel->setWorkspace($ws);
        $channel->setCreator($admin);
        $this->entityManager->persist($channel);

        $msg = new Message();
        $msg->setContent('Hello metrics test');
        $msg->setAuthor($admin);
        $msg->setChannel($channel);
        $this->entityManager->persist($msg);

        $this->entityManager->flush();

        $this->adminUser = $admin;
        $this->normalUser = $user;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $msgRepo = $this->entityManager->getRepository(Message::class);
        foreach ($msgRepo->findAll() as $m) {
            if ($m->getContent() !== 'Hello metrics test') {
                continue;
            }
            $this->entityManager->remove($m);
        }

        $chRepo = $this->entityManager->getRepository(Channel::class);
        $ch = $chRepo->findOneBy(['slug' => 'general-metrics']);
        if ($ch) {
            $this->entityManager->remove($ch);
        }

        $wsRepo = $this->entityManager->getRepository(Workspace::class);
        $ws = $wsRepo->findOneBy(['slug' => 'metrics-test-ws']);
        if ($ws) {
            $this->entityManager->remove($ws);
        }

        $userRepo = $this->entityManager->getRepository(User::class);
        foreach (['metrics_admin', 'metrics_user'] as $u) {
            $user = $userRepo->findOneBy(['username' => $u]);
            if ($user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function anonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin/metrics');
        $this->assertResponseRedirects('/login');
    }

    #[Test]
    public function normalUserIsForbidden(): void
    {
        $this->client->loginUser($this->normalUser);
        $this->client->request('GET', '/admin/metrics');
        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function adminUserCanViewMetricsDashboard(): void
    {
        $this->client->loginUser($this->adminUser);
        $crawler = $this->client->request('GET', '/admin/metrics');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Métriques & Activité');
        $this->assertSelectorExists('#activity-timeline-chart');
        $this->assertSelectorExists('#storage-breakdown-chart');
    }

    #[Test]
    public function adminUserCanFetchHtmxPartial(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request(
            'GET',
            '/admin/metrics?period=7d',
            [],
            [],
            [
                'HTTP_HX-Request' => 'true',
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.metrics-kpi-grid');
        $this->assertSelectorExists('.metrics-charts-grid');
    }
}
