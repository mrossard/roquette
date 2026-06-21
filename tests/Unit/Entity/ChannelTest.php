<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ChannelTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor defaults
    // -------------------------------------------------------------------------

    #[Test]
    public function constructorSetsCreatedAt(): void
    {
        $before = new \DateTimeImmutable();
        $channel = new Channel();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $channel->getCreatedAt());
        $this->assertLessThanOrEqual($after, $channel->getCreatedAt());
    }

    #[Test]
    public function isPrivateDefaultsToFalse(): void
    {
        $channel = new Channel();
        $this->assertFalse($channel->isPrivate());
    }

    #[Test]
    public function isDmDefaultsToFalse(): void
    {
        $channel = new Channel();
        $this->assertFalse($channel->isDm());
    }

    #[Test]
    public function membersCollectionIsEmptyByDefault(): void
    {
        $channel = new Channel();
        $this->assertCount(0, $channel->getMembers());
    }

    #[Test]
    public function messagesCollectionIsEmptyByDefault(): void
    {
        $channel = new Channel();
        $this->assertCount(0, $channel->getMessages());
    }

    // -------------------------------------------------------------------------
    // Members management
    // -------------------------------------------------------------------------

    #[Test]
    public function addMemberAddsUserToCollection(): void
    {
        $channel = new Channel();
        $user = new User();

        $channel->addMember($user);

        $this->assertTrue($channel->getMembers()->contains($user));
    }

    #[Test]
    public function addMemberDoesNotDuplicate(): void
    {
        $channel = new Channel();
        $user = new User();

        $channel->addMember($user);
        $channel->addMember($user);

        $this->assertCount(1, $channel->getMembers());
    }

    #[Test]
    public function removeMemberRemovesUserFromCollection(): void
    {
        $channel = new Channel();
        $user = new User();

        $channel->addMember($user);
        $channel->removeMember($user);

        $this->assertFalse($channel->getMembers()->contains($user));
    }

    // -------------------------------------------------------------------------
    // Messages management
    // -------------------------------------------------------------------------

    #[Test]
    public function addMessageAddsToCollectionAndSetsChannel(): void
    {
        $channel = new Channel();
        $message = new Message();

        $channel->addMessage($message);

        $this->assertTrue($channel->getMessages()->contains($message));
        $this->assertSame($channel, $message->getChannel());
    }

    #[Test]
    public function addMessageDoesNotDuplicate(): void
    {
        $channel = new Channel();
        $message = new Message();

        $channel->addMessage($message);
        $channel->addMessage($message);

        $this->assertCount(1, $channel->getMessages());
    }

    #[Test]
    public function removeMessageRemovesFromCollectionAndNullsChannel(): void
    {
        $channel = new Channel();
        $message = new Message();

        $channel->addMessage($message);
        $channel->removeMessage($message);

        $this->assertFalse($channel->getMessages()->contains($message));
        $this->assertNull($message->getChannel());
    }

    // -------------------------------------------------------------------------
    // getDmPartner()
    // -------------------------------------------------------------------------

    #[Test]
    public function getDmPartnerReturnsOtherMember(): void
    {
        $channel = new Channel();
        $userA = $this->createUserWithId(1, 'alice');
        $userB = $this->createUserWithId(2, 'bob');

        $channel->addMember($userA);
        $channel->addMember($userB);

        $partner = $channel->getDmPartner($userA);

        $this->assertSame($userB, $partner);
    }

    #[Test]
    public function getDmPartnerReturnsNullWhenAlone(): void
    {
        $channel = new Channel();
        $userA = $this->createUserWithId(1, 'alice');

        $channel->addMember($userA);

        $partner = $channel->getDmPartner($userA);

        $this->assertNull($partner);
    }

    // -------------------------------------------------------------------------
    // Properties
    // -------------------------------------------------------------------------

    #[Test]
    public function nameCanBeSetAndRetrieved(): void
    {
        $channel = new Channel();
        $channel->setName('général');

        $this->assertSame('général', $channel->getName());
    }

    #[Test]
    public function slugCanBeSetAndRetrieved(): void
    {
        $channel = new Channel();
        $channel->setSlug('general');

        $this->assertSame('general', $channel->getSlug());
    }

    #[Test]
    public function descriptionDefaultsToNull(): void
    {
        $channel = new Channel();
        $this->assertNull($channel->getDescription());
    }

    #[Test]
    public function pinnedMessageDefaultsToNull(): void
    {
        $channel = new Channel();
        $this->assertNull($channel->getPinnedMessage());
    }

    #[Test]
    public function pinnedMessageCanBeSetAndRetrieved(): void
    {
        $channel = new Channel();
        $message = new Message();

        $channel->setPinnedMessage($message);

        $this->assertSame($message, $channel->getPinnedMessage());
    }

    // -------------------------------------------------------------------------
    // Administrators management
    // -------------------------------------------------------------------------

    #[Test]
    public function administratorsIsEmptyByDefault(): void
    {
        $channel = new Channel();
        $this->assertCount(0, $channel->getAdministrators());
    }

    #[Test]
    public function addAdministratorAddsUserToCollection(): void
    {
        $channel = new Channel();
        $user = new User();

        $channel->addAdministrator($user);

        $this->assertTrue($channel->getAdministrators()->contains($user));
    }

    #[Test]
    public function removeAdministratorRemovesUserFromCollection(): void
    {
        $channel = new Channel();
        $user = new User();

        $channel->addAdministrator($user);
        $channel->removeAdministrator($user);

        $this->assertFalse($channel->getAdministrators()->contains($user));
    }

    #[Test]
    public function isAdministratorReturnsTrueForCreator(): void
    {
        $channel = new Channel();
        $creator = $this->createUserWithId(1, 'creator');
        $channel->setCreator($creator);

        $this->assertTrue($channel->isAdministrator($creator));
    }

    #[Test]
    public function isAdministratorReturnsTrueForAdministrator(): void
    {
        $channel = new Channel();
        $admin = $this->createUserWithId(2, 'admin');
        $channel->addAdministrator($admin);

        $this->assertTrue($channel->isAdministrator($admin));
    }

    #[Test]
    public function isAdministratorReturnsFalseForNonAdmin(): void
    {
        $channel = new Channel();
        $user = $this->createUserWithId(3, 'user');

        $this->assertFalse($channel->isAdministrator($user));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a User with a forced ID using reflection (no DB needed).
     */
    private function createUserWithId(int $id, string $username): User
    {
        $user = new User();
        $user->setUsername($username);

        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
