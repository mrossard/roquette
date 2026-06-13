<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Entity\Poll;
use App\Entity\PollOption;
use App\Entity\PollVote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PollTest extends TestCase
{
    #[Test]
    public function constructorInitializesDefaults(): void
    {
        $before = new \DateTimeImmutable();
        $poll = new Poll();
        $after = new \DateTimeImmutable();

        $this->assertFalse($poll->isAllowMultiple());
        $this->assertGreaterThanOrEqual($before, $poll->getCreatedAt());
        $this->assertLessThanOrEqual($after, $poll->getCreatedAt());
        $this->assertCount(0, $poll->getOptions());
    }

    #[Test]
    public function addAndRemoveOptionManageBidirectionalRelation(): void
    {
        $poll = new Poll();
        $option = new PollOption();

        $poll->addOption($option);
        $this->assertCount(1, $poll->getOptions());
        $this->assertSame($poll, $option->getPoll());

        $poll->removeOption($option);
        $this->assertCount(0, $poll->getOptions());
        $this->assertNull($option->getPoll());
    }

    #[Test]
    public function getTotalVotesSumsAllOptionVotes(): void
    {
        $poll = new Poll();
        $optionA = new PollOption();
        $optionB = new PollOption();

        $poll->addOption($optionA);
        $poll->addOption($optionB);

        // Add dummy votes
        $optionA->addVote(new PollVote());
        $optionA->addVote(new PollVote());
        $optionB->addVote(new PollVote());

        $this->assertSame(3, $poll->getTotalVotes());
    }
}
