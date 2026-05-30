<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    // -------------------------------------------------------------------------
    // getRoles()
    // -------------------------------------------------------------------------

    #[Test]
    public function getRolesAlwaysContainsRoleUser(): void
    {
        $user = new User();
        $user->setUsername('jean');
        $user->setRoles([]);

        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    #[Test]
    public function getRolesGrantsRoleAdminForAdminUsername(): void
    {
        $user = new User();
        $user->setUsername('admin');
        $user->setRoles([]);

        $this->assertContains('ROLE_ADMIN', $user->getRoles());
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    #[Test]
    public function getRolesGrantsRoleAdminForUpperCaseAdmin(): void
    {
        $user = new User();
        $user->setUsername('ADMIN');
        $user->setRoles([]);

        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    #[Test]
    public function getRolesDoesNotGrantAdminForRegularUser(): void
    {
        $user = new User();
        $user->setUsername('jean');
        $user->setRoles([]);

        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    #[Test]
    public function getRolesDeduplicatesRoles(): void
    {
        $user = new User();
        $user->setUsername('jean');
        $user->setRoles(['ROLE_USER', 'ROLE_USER']);

        $roles = $user->getRoles();
        $this->assertSame(array_unique($roles), $roles);
    }

    // -------------------------------------------------------------------------
    // getHue()
    // -------------------------------------------------------------------------

    #[Test]
    public function getHueReturnsCustomHueWhenSet(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setCustomHue(180);

        $this->assertSame(180, $user->getHue());
    }

    #[Test]
    public function getHueReturnsDeterministicValueFromUsername(): void
    {
        $user1 = new User();
        $user1->setUsername('alice');

        $user2 = new User();
        $user2->setUsername('alice');

        $this->assertSame($user1->getHue(), $user2->getHue());
    }

    #[Test]
    public function getHueReturnsDifferentValueForDifferentUsernames(): void
    {
        $user1 = new User();
        $user1->setUsername('alice');

        $user2 = new User();
        $user2->setUsername('bob');

        // Very unlikely to be equal for different names
        $this->assertNotEquals($user1->getHue(), $user2->getHue());
    }

    #[Test]
    public function getHueIsInValidRange(): void
    {
        $user = new User();
        $user->setUsername('somename');
        $hue = $user->getHue();

        $this->assertGreaterThanOrEqual(0, $hue);
        $this->assertLessThan(360, $hue);
    }

    // -------------------------------------------------------------------------
    // getStatus()
    // -------------------------------------------------------------------------

    #[Test]
    public function getStatusReturnsOfflineWhenNoLastActiveAt(): void
    {
        $user = new User();
        $user->setUsername('alice');

        $this->assertSame('offline', $user->getStatus());
    }

    #[Test]
    public function getStatusReturnsOnlineWhenActiveRecently(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setLastActiveAt(new \DateTimeImmutable('-1 minute'));

        $this->assertSame('online', $user->getStatus());
    }

    #[Test]
    public function getStatusReturnsOfflineWhenLastActiveMoreThanFiveMinutesAgo(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setLastActiveAt(new \DateTimeImmutable('-10 minutes'));

        $this->assertSame('offline', $user->getStatus());
    }

    #[Test]
    public function getStatusReturnsStatusOverrideWhenSet(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setLastActiveAt(new \DateTimeImmutable('-1 minute'));
        $user->setStatusOverride('busy');

        $this->assertSame('busy', $user->getStatus());
    }

    #[Test]
    public function getStatusUsesAutoDetectionWhenOverrideIsAuto(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setLastActiveAt(new \DateTimeImmutable('-1 minute'));
        $user->setStatusOverride('auto');

        $this->assertSame('online', $user->getStatus());
    }

    // -------------------------------------------------------------------------
    // getStatusLabel()
    // -------------------------------------------------------------------------

    #[DataProvider('statusLabelProvider')]
    #[Test]
    public function getStatusLabelReturnsCorrectLabel(string $override, string $expectedLabel): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setStatusOverride($override);

        $this->assertSame($expectedLabel, $user->getStatusLabel());
    }

    public static function statusLabelProvider(): array
    {
        return [
            'busy'    => ['busy', 'Occupé'],
            'away'    => ['away', 'Absent'],
            'offline' => ['offline', 'Hors ligne'],
        ];
    }

    // -------------------------------------------------------------------------
    // getUserIdentifier()
    // -------------------------------------------------------------------------

    #[Test]
    public function getUserIdentifierReturnsUsername(): void
    {
        $user = new User();
        $user->setUsername('alice');

        $this->assertSame('alice', $user->getUserIdentifier());
    }

    // -------------------------------------------------------------------------
    // Saved messages
    // -------------------------------------------------------------------------

    #[Test]
    public function addSavedMessageAddsMessageToCollection(): void
    {
        $user    = new User();
        $message = new \App\Entity\Message();

        $user->addSavedMessage($message);

        $this->assertTrue($user->getSavedMessages()->contains($message));
    }

    #[Test]
    public function addSavedMessageDoesNotDuplicate(): void
    {
        $user    = new User();
        $message = new \App\Entity\Message();

        $user->addSavedMessage($message);
        $user->addSavedMessage($message);

        $this->assertCount(1, $user->getSavedMessages());
    }

    #[Test]
    public function removeSavedMessageRemovesItFromCollection(): void
    {
        $user    = new User();
        $message = new \App\Entity\Message();

        $user->addSavedMessage($message);
        $user->removeSavedMessage($message);

        $this->assertFalse($user->getSavedMessages()->contains($message));
    }

    // -------------------------------------------------------------------------
    // Mention notifications
    // -------------------------------------------------------------------------

    #[Test]
    public function mentionNotificationsEnabledByDefault(): void
    {
        $user = new User();
        $this->assertTrue($user->isMentionNotificationsEnabled());
    }

    #[Test]
    public function setMentionNotificationsEnabledPersistsValue(): void
    {
        $user = new User();
        $user->setMentionNotificationsEnabled(false);

        $this->assertFalse($user->isMentionNotificationsEnabled());
    }

    // -------------------------------------------------------------------------
    // Favorite channels
    // -------------------------------------------------------------------------

    #[Test]
    public function getFavoriteChannelIdsReturnsEmptyArrayByDefault(): void
    {
        $user = new User();
        $this->assertSame([], $user->getFavoriteChannelIds());
    }

    #[Test]
    public function addFavoriteChannelAddsIdToArray(): void
    {
        $user = new User();
        $channel = $this->createMock(\App\Entity\Channel::class);
        $channel->method('getId')->willReturn(42);

        $user->addFavoriteChannel($channel);

        $this->assertSame([42], $user->getFavoriteChannelIds());
        $this->assertTrue($user->isChannelFavorite($channel));
    }

    #[Test]
    public function removeFavoriteChannelRemovesIdFromArray(): void
    {
        $user = new User();
        $channel = $this->createMock(\App\Entity\Channel::class);
        $channel->method('getId')->willReturn(42);

        $user->addFavoriteChannel($channel);
        $user->removeFavoriteChannel($channel);

        $this->assertSame([], $user->getFavoriteChannelIds());
        $this->assertFalse($user->isChannelFavorite($channel));
    }
}
