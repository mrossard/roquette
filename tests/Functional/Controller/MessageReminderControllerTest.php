<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\Reminder;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
final class MessageReminderControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private User $otherUser;
    private Channel $testChannel;
    private Channel $privateChannel;
    private Message $testMessage;
    private Message $privateMessage;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        $passwordHasher = $container->get('security.user_password_hasher');

        $user = new User();
        $user->setUsername('remind_user1');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));
        $this->entityManager->persist($user);

        $other = new User();
        $other->setUsername('remind_user2');
        $other->setRoles(['ROLE_USER']);
        $other->setPassword($passwordHasher->hashPassword($other, 'password123'));
        $this->entityManager->persist($other);

        $ws = new Workspace();
        $ws->setName('Reminder Test WS');
        $ws->setSlug('reminder-test-ws');
        $ws->setCreator($user);
        $ws->addMember($user);
        $ws->addMember($other);
        $this->entityManager->persist($ws);

        $channel = new Channel();
        $channel->setName('remind-general');
        $channel->setSlug('remind-general');
        $channel->setWorkspace($ws);
        $channel->setCreator($user);
        $this->entityManager->persist($channel);

        $privChannel = new Channel();
        $privChannel->setName('remind-secret');
        $privChannel->setSlug('remind-secret');
        $privChannel->setIsPrivate(true);
        $privChannel->setCreator($other);
        $privChannel->addMember($other);
        $this->entityManager->persist($privChannel);

        $msg = new Message();
        $msg->setContent('Important message to remember');
        $msg->setAuthor($other);
        $msg->setChannel($channel);
        $this->entityManager->persist($msg);

        $privMsg = new Message();
        $privMsg->setContent('Secret message');
        $privMsg->setAuthor($other);
        $privMsg->setChannel($privChannel);
        $this->entityManager->persist($privMsg);

        $this->entityManager->flush();

        $this->testUser = $user;
        $this->otherUser = $other;
        $this->testChannel = $channel;
        $this->privateChannel = $privChannel;
        $this->testMessage = $msg;
        $this->privateMessage = $privMsg;
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $remRepo = $this->entityManager->getRepository(Reminder::class);
        foreach ($remRepo->findAll() as $rem) {
            $this->entityManager->remove($rem);
        }

        $msgRepo = $this->entityManager->getRepository(Message::class);
        foreach ($msgRepo->findAll() as $m) {
            if ($m->getContent() !== 'Important message to remember' && $m->getContent() !== 'Secret message') {
                continue;
            }
            $this->entityManager->remove($m);
        }

        $chRepo = $this->entityManager->getRepository(Channel::class);
        foreach (['remind-general', 'remind-secret'] as $slug) {
            $ch = $chRepo->findOneBy(['slug' => $slug]);
            if ($ch) {
                $this->entityManager->remove($ch);
            }
        }

        $wsRepo = $this->entityManager->getRepository(Workspace::class);
        $ws = $wsRepo->findOneBy(['slug' => 'reminder-test-ws']);
        if ($ws) {
            $this->entityManager->remove($ws);
        }

        $userRepo = $this->entityManager->getRepository(User::class);
        foreach (['remind_user1', 'remind_user2'] as $u) {
            $user = $userRepo->findOneBy(['username' => $u]);
            if ($user) {
                $this->entityManager->remove($user);
            }
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function anonymousUserIsRedirected(): void
    {
        $this->client->request('POST', '/messages/' . $this->testMessage->getId() . '/remind');
        $this->assertResponseRedirects('/login');
    }

    #[Test]
    public function authenticatedUserCanScheduleMessageReminder(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request(
            'POST',
            '/messages/' . $this->testMessage->getId() . '/remind',
            [
                'preset' => '1h',
            ],
            [],
            [
                'HTTP_HX-Request' => 'true',
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.btn-reply-subtle');
        $this->assertSelectorTextContains('.reminder-action-container', 'Rappel');

        $reminder = $this->entityManager
            ->getRepository(Reminder::class)
            ->findOneBy([
                'targetMessage' => $this->testMessage,
                'user' => $this->testUser,
            ]);
        $this->assertNotNull($reminder);
        $this->assertContains($reminder->getStatus(), ['pending', 'delivered']);
        $this->assertSame($this->testMessage->getId(), $reminder->getTargetMessage()?->getId());
    }

    #[Test]
    public function userCannotScheduleReminderOnInaccessiblePrivateChannel(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/messages/' . $this->privateMessage->getId() . '/remind', [
            'preset' => '1h',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function userCanCancelReminder(): void
    {
        $this->client->loginUser($this->testUser);

        $reminder = new Reminder();
        $reminder->setUser($this->testUser);
        $reminder->setChannel($this->testChannel);
        $reminder->setTargetMessage($this->testMessage);
        $reminder->setMessage('Test cancel');
        $reminder->setScheduledAt(new \DateTimeImmutable('+1 hour'));
        $reminder->setStatus('pending');
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->client->request(
            'POST',
            '/reminders/' . $reminder->getId() . '/cancel',
            [],
            [],
            [
                'HTTP_HX-Request' => 'true',
            ],
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $updated = $this->entityManager->getRepository(Reminder::class)->find($reminder->getId());
        $this->assertSame('cancelled', $updated?->getStatus());
    }

    #[Test]
    public function userCanViewRemindersList(): void
    {
        $this->client->loginUser($this->testUser);

        $reminder = new Reminder();
        $reminder->setUser($this->testUser);
        $reminder->setChannel($this->testChannel);
        $reminder->setTargetMessage($this->testMessage);
        $reminder->setMessage('Check meeting notes');
        $reminder->setScheduledAt(new \DateTimeImmutable('+2 hours'));
        $reminder->setStatus('pending');
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        $this->client->request(
            'GET',
            '/reminders',
            [],
            [],
            [
                'HTTP_HX-Request' => 'true',
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#reminders-modal-content', 'Check meeting notes');
    }
}
