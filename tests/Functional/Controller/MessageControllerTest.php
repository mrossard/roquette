<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class MessageControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $container = $this->client->getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $this->cleanup();

        // Create a test user
        $user = new User();
        $user->setUsername('test_msg_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        // Create a test channel
        $channel = new Channel();
        $channel->setName('Test Msg Channel');
        $channel->setSlug('test-msg-channel');
        $channel->setCreator($user);
        $channel->addMember($user);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $this->testUser = $user;
        $this->channel = $channel;
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $channelRepository = $this->entityManager->getRepository(Channel::class);
        $messageRepository = $this->entityManager->getRepository(Message::class);

        $messages = $messageRepository->findAll();
        foreach ($messages as $msg) {
            $this->entityManager->remove($msg);
        }

        $users = $userRepository->findBy(['username' => 'test_msg_user']);
        foreach ($users as $u) {
            $this->entityManager->remove($u);
        }

        $channels = $channelRepository->findBy(['slug' => 'test-msg-channel']);
        foreach ($channels as $c) {
            $this->entityManager->remove($c);
        }

        $this->entityManager->flush();
    }

    #[Test]
    public function testPreviewMeCommand(): void
    {
        $this->client->request(
            'POST',
            '/api/message/preview',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['content' => '/me is testing preview']),
        );

        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        static::assertStringContainsString('<em>is testing preview</em>', $responseContent);
    }

    #[Test]
    public function testPublishMeCommand(): void
    {
        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'message' => '/me is typing a long message',
        ]);

        $this->assertResponseIsSuccessful();

        $messageRepository = $this->entityManager->getRepository(Message::class);
        $messages = $messageRepository->findBy(['author' => $this->testUser]);

        static::assertCount(1, $messages);
        static::assertSame('/me is typing a long message', $messages[0]->getContent());
    }

    #[Test]
    public function testPublishPollMessage(): void
    {
        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'poll_question' => 'Quel est votre langage préféré ?',
            'poll_options' => ['PHP', 'TypeScript', 'Rust'],
        ]);

        $this->assertResponseIsSuccessful();

        $messageRepository = $this->entityManager->getRepository(Message::class);
        $messages = $messageRepository->findBy(['author' => $this->testUser]);

        // Find the message with the poll
        $pollMessage = null;
        foreach ($messages as $msg) {
            if (!$msg->isPoll()) {
                continue;
            }

            $pollMessage = $msg;
            break;
        }

        static::assertNotNull($pollMessage);
        static::assertTrue($pollMessage->isPoll());

        $poll = $pollMessage->getPoll();
        static::assertNotNull($poll);
        static::assertSame('Quel est votre langage préféré ?', $poll->getQuestion());
        static::assertFalse($poll->isAllowMultiple());
        static::assertCount(3, $poll->getOptions());
        static::assertSame('PHP', $poll->getOptions()[0]->getText());
        static::assertSame('TypeScript', $poll->getOptions()[1]->getText());
        static::assertSame('Rust', $poll->getOptions()[2]->getText());
    }

    #[Test]
    public function testPublishPollRequiresMinTwoOptions(): void
    {
        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'poll_question' => 'Un seul choix ?',
            'poll_options' => ['Option Unique'],
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function testPublishPollWithAllowMultiple(): void
    {
        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'poll_question' => 'Choix multiples ?',
            'poll_options' => ['A', 'B', 'C'],
            'allow_multiple' => '1',
        ]);

        $this->assertResponseIsSuccessful();

        $messageRepository = $this->entityManager->getRepository(Message::class);
        $messages = $messageRepository->findBy(['author' => $this->testUser]);

        $pollMessage = null;
        foreach ($messages as $msg) {
            if (!($msg->isPoll() && $msg->getPoll()->getQuestion() === 'Choix multiples ?')) {
                continue;
            }

            $pollMessage = $msg;
            break;
        }

        static::assertNotNull($pollMessage);
        $poll = $pollMessage->getPoll();
        static::assertNotNull($poll);
        static::assertTrue($poll->isAllowMultiple());
    }

    #[Test]
    public function testEditPollMessage(): void
    {
        // 1. Create a poll message
        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'poll_question' => 'Original Question?',
            'poll_options' => ['Opt1', 'Opt2'],
        ]);
        $this->assertResponseIsSuccessful();

        $messageRepository = $this->entityManager->getRepository(Message::class);
        $messages = $messageRepository->findBy(['author' => $this->testUser]);

        $pollMessage = null;
        foreach ($messages as $msg) {
            if (!($msg->isPoll() && $msg->getPoll()->getQuestion() === 'Original Question?')) {
                continue;
            }

            $pollMessage = $msg;
            break;
        }
        static::assertNotNull($pollMessage);

        // 2. Modify the poll
        $this->client->request('POST', sprintf('/messages/%d/edit', $pollMessage->getId()), [
            'poll_question' => 'Updated Question?',
            'poll_options' => ['New Opt1', 'New Opt2', 'Added Opt3'],
            'allow_multiple' => '1',
        ]);
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $updatedMessage = $messageRepository->find($pollMessage->getId());
        static::assertNotNull($updatedMessage);
        static::assertTrue($updatedMessage->isPoll());

        $poll = $updatedMessage->getPoll();
        static::assertSame('Updated Question?', $poll->getQuestion());
        static::assertTrue($poll->isAllowMultiple());
        static::assertCount(3, $poll->getOptions());
        static::assertSame('New Opt1', $poll->getOptions()[0]->getText());
        static::assertSame('New Opt2', $poll->getOptions()[1]->getText());
        static::assertSame('Added Opt3', $poll->getOptions()[2]->getText());
    }

    #[Test]
    public function testEditPollWithVotesIsBlocked(): void
    {
        // 1. Create a poll message with multiple choice allowed
        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'poll_question' => 'Vote test?',
            'poll_options' => ['Option A', 'Option B'],
            'allow_multiple' => '1',
        ]);
        $this->assertResponseIsSuccessful();

        $messageRepository = $this->entityManager->getRepository(Message::class);
        $messages = $messageRepository->findBy(['author' => $this->testUser]);

        $pollMessage = null;
        foreach ($messages as $msg) {
            if (!($msg->isPoll() && $msg->getPoll()->getQuestion() === 'Vote test?')) {
                continue;
            }

            $pollMessage = $msg;
            break;
        }
        static::assertNotNull($pollMessage);
        $optionA = $pollMessage->getPoll()->getOptions()[0];

        // 2. Vote on option A
        $this->client->request('POST', sprintf('/poll/%d/vote', $optionA->getId()));
        $this->assertResponseIsSuccessful();

        // 3. Try to GET the edit form -> should return 400
        $this->client->request('GET', sprintf('/messages/%d/edit', $pollMessage->getId()));
        $this->assertResponseStatusCodeSame(400);

        $this->assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function testHelpSlashCommand(): void
    {
        $messageBusMock = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $messageBusMock
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function ($message) {
                if ($message instanceof \App\Message\LlmQueryMessage) {
                    $this->assertSame('Comment fonctionne roquette ?', $message->getQuestion());
                }

                return new \Symfony\Component\Messenger\Envelope(new \stdClass());
            });

        $this->client->getContainer()->set(\Symfony\Component\Messenger\MessageBusInterface::class, $messageBusMock);

        $this->client->request('POST', '/channels/test-msg-channel/publish', [
            'message' => '/help Comment fonctionne roquette ?',
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        static::assertStringContainsString('Assistant Roquette', $responseContent);
        static::assertStringContainsString('Comment fonctionne roquette ?', $responseContent);
        static::assertStringContainsString('En attente de l\'Assistant Roquette... ⏳', $responseContent);
    }
}
