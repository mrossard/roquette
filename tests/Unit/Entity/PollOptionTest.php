<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\PollOption;
use App\Entity\PollVote;
use App\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PollOptionTest extends TestCase
{
    #[Test]
    public function voteCountIsCorrect(): void
    {
        $option = new PollOption();
        $this->assertSame(0, $option->getVoteCount());

        $vote1 = new PollVote();
        $vote2 = new PollVote();

        $option->addVote($vote1);
        $this->assertSame(1, $option->getVoteCount());

        $option->addVote($vote2);
        $this->assertSame(2, $option->getVoteCount());

        $option->removeVote($vote1);
        $this->assertSame(1, $option->getVoteCount());
    }

    #[Test]
    public function hasVotedByReturnsCorrectStatus(): void
    {
        $user1 = $this->createMock(User::class);
        $user1->method('getId')->willReturn(1);

        $user2 = $this->createMock(User::class);
        $user2->method('getId')->willReturn(2);

        $option = new PollOption();

        $vote = new PollVote();
        $vote->setUser($user1);
        $option->addVote($vote);

        $this->assertTrue($option->hasVotedBy($user1));
        $this->assertFalse($option->hasVotedBy($user2));
    }

    #[Test]
    public function getVotersReturnsCorrectCollection(): void
    {
        $user1 = $this->createMock(User::class);
        $user1->method('getId')->willReturn(1);

        $user2 = $this->createMock(User::class);
        $user2->method('getId')->willReturn(2);

        $option = new PollOption();

        $vote1 = new PollVote();
        $vote1->setUser($user1);
        $option->addVote($vote1);

        $vote2 = new PollVote();
        $vote2->setUser($user2);
        $option->addVote($vote2);

        $voters = $option->getVoters();
        $this->assertCount(2, $voters);
        $this->assertTrue($voters->contains($user1));
        $this->assertTrue($voters->contains($user2));
    }
}
