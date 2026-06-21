<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\PollOption;
use App\Entity\PollVote;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class PollControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private $client;
    private User $testUser;
    private Channel $channel;
    private Message $message;
    private Poll $poll;
    private PollOption $optionA;
    private PollOption $optionB;

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
        $user->setUsername('test_poll_user');
        $user->setRoles(['ROLE_USER']);

        $passwordHasher = $container->get('security.user_password_hasher');
        $user->setPassword($passwordHasher->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);

        // Create a test channel
        $channel = new Channel();
        $channel->setName('Test Channel Poll');
        $channel->setSlug('test-channel-poll');
        $channel->setCreator($user);
        $channel->addMember($user);

        $this->entityManager->persist($channel);

        // Create a message
        $message = new Message();
        $message->setChannel($channel);
        $message->setAuthor($user);

        $this->entityManager->persist($message);

        // Create a poll
        $poll = new Poll();
        $poll->setQuestion('La question ?');
        $poll->setAllowMultiple(false);
        $poll->setMessage($message);
        $message->setPoll($poll);

        $this->entityManager->persist($poll);

        // Options
        $optionA = new PollOption();
        $optionA->setText('Option A');
        $optionA->setPosition(0);
        $poll->addOption($optionA);
        $this->entityManager->persist($optionA);

        $optionB = new PollOption();
        $optionB->setText('Option B');
        $optionB->setPosition(1);
        $poll->addOption($optionB);
        $this->entityManager->persist($optionB);

        $this->entityManager->flush();

        $this->testUser = $user;
        $this->channel = $channel;
        $this->message = $message;
        $this->poll = $poll;
        $this->optionA = $optionA;
        $this->optionB = $optionB;
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->executeStatement('DELETE FROM poll_vote WHERE user_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_poll_user',
        ]);
        $conn->executeStatement(
            'DELETE FROM poll_option WHERE poll_id IN (SELECT p.id FROM poll p JOIN message m ON p.message_id = m.id JOIN "user" u ON m.author_id = u.id WHERE u.username = ?)',
            [
                'test_poll_user',
            ],
        );
        $conn->executeStatement(
            'DELETE FROM poll WHERE message_id IN (SELECT id FROM message WHERE author_id IN (SELECT id FROM "user" WHERE username = ?))',
            [
                'test_poll_user',
            ],
        );
        $conn->executeStatement('DELETE FROM message WHERE author_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_poll_user',
        ]);
        $conn->executeStatement('DELETE FROM user_channel_read WHERE user_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_poll_user',
        ]);
        $conn->executeStatement('DELETE FROM channel WHERE creator_id IN (SELECT id FROM "user" WHERE username = ?)', [
            'test_poll_user',
        ]);
        $conn->executeStatement('DELETE FROM "user" WHERE username = ?', ['test_poll_user']);
    }

    #[Test]
    public function testVoteOnOption(): void
    {
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionA->getId()));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $voteRepo = $this->entityManager->getRepository(PollVote::class);
        $votes = $voteRepo->findBy([
            'option' => $this->optionA,
            'user' => $this->testUser,
        ]);
        static::assertCount(1, $votes);
    }

    #[Test]
    public function testVoteToggleOff(): void
    {
        // 1. Vote once
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionA->getId()));
        $this->assertResponseIsSuccessful();

        // 2. Vote again (toggles off)
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionA->getId()));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $voteRepo = $this->entityManager->getRepository(PollVote::class);
        $votes = $voteRepo->findBy([
            'option' => $this->optionA,
            'user' => $this->testUser,
        ]);
        static::assertCount(0, $votes);
    }

    #[Test]
    public function testUniqueChoiceRemovesPreviousVote(): void
    {
        // 1. Vote on A
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionA->getId()));
        $this->assertResponseIsSuccessful();

        // 2. Vote on B
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionB->getId()));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $voteRepo = $this->entityManager->getRepository(PollVote::class);

        $votesA = $voteRepo->findBy([
            'option' => $this->optionA,
            'user' => $this->testUser,
        ]);
        $votesB = $voteRepo->findBy([
            'option' => $this->optionB,
            'user' => $this->testUser,
        ]);

        static::assertCount(0, $votesA);
        static::assertCount(1, $votesB);
    }

    #[Test]
    public function testMultipleChoiceKeepsBothVotes(): void
    {
        // Change allowMultiple to true
        $poll = $this->entityManager->getRepository(Poll::class)->find($this->poll->getId());
        $poll->setAllowMultiple(true);
        $this->entityManager->flush();

        // 1. Vote on A
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionA->getId()));
        $this->assertResponseIsSuccessful();

        // 2. Vote on B
        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionB->getId()));
        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();
        $voteRepo = $this->entityManager->getRepository(PollVote::class);

        $votesA = $voteRepo->findBy([
            'option' => $this->optionA,
            'user' => $this->testUser,
        ]);
        $votesB = $voteRepo->findBy([
            'option' => $this->optionB,
            'user' => $this->testUser,
        ]);

        static::assertCount(1, $votesA);
        static::assertCount(1, $votesB);
    }

    #[Test]
    public function testCannotVoteOnOtherChannelPoll(): void
    {
        // Make the channel private and remove user from it
        $channel = $this->entityManager->getRepository(Channel::class)->find($this->channel->getId());
        $channel->setIsPrivate(true);
        $channel->removeMember($this->testUser);
        $this->entityManager->flush();

        $this->client->request('POST', sprintf('/poll/%d/vote', $this->optionA->getId()));
        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function testInvalidOptionId(): void
    {
        $this->client->request('POST', '/poll/999999/vote');
        $this->assertResponseStatusCodeSame(404);
    }
}
