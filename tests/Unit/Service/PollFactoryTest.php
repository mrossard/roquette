<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Message;
use App\Entity\PollVote;
use App\Entity\User;
use App\Service\PollFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PollFactoryTest extends TestCase
{
    private PollFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new PollFactory();
    }

    #[Test]
    public function hasValidOptionsReturnsTrueForTwoOrMore(): void
    {
        $this->assertTrue($this->factory->hasValidOptions(['A', 'B']));
        $this->assertTrue($this->factory->hasValidOptions(['A', 'B', 'C']));
        $this->assertFalse($this->factory->hasValidOptions(['A']));
        $this->assertFalse($this->factory->hasValidOptions([]));
        $this->assertFalse($this->factory->hasValidOptions(null));
    }

    #[Test]
    public function validateThrowsOnEmptyQuestion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(PollFactory::ERROR_EMPTY_QUESTION);

        $this->factory->validate('   ', ['Opt 1', 'Opt 2']);
    }

    #[Test]
    public function validateThrowsOnLessThanTwoOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(PollFactory::ERROR_MIN_OPTIONS);

        $this->factory->validate('Quelle couleur ?', ['Bleu']);
    }

    #[Test]
    public function createPollBuildsPollAndOptionsCorrectly(): void
    {
        $message = new Message();
        $poll = $this->factory->createPoll(
            $message,
            '   Votre langage préféré ?   ',
            [' PHP ', ' JS ', ' Python '],
            true,
        );

        $this->assertSame($message, $poll->getMessage());
        $this->assertSame($poll, $message->getPoll());
        $this->assertSame('Votre langage préféré ?', $poll->getQuestion());
        $this->assertTrue($poll->isAllowMultiple());
        $this->assertCount(3, $poll->getOptions());

        $options = $poll->getOptions()->getValues();
        $this->assertSame('PHP', $options[0]->getText());
        $this->assertSame(0, $options[0]->getPosition());
        $this->assertSame($poll, $options[0]->getPoll());

        $this->assertSame('JS', $options[1]->getText());
        $this->assertSame(1, $options[1]->getPosition());

        $this->assertSame('Python', $options[2]->getText());
        $this->assertSame(2, $options[2]->getPosition());
    }

    #[Test]
    public function updatePollUpdatesQuestionAndSynchronizesOptions(): void
    {
        $message = new Message();
        $poll = $this->factory->createPoll($message, 'Ancienne question', ['Opt 1', 'Opt 2', 'Opt 3']);

        $user = new User();
        $vote = new PollVote();
        $vote->setUser($user);
        $poll->getOptions()->first()->addVote($vote);
        $this->assertCount(1, $poll->getOptions()->first()->getVotes());

        // Update: change question, allowMultiple, edit Opt 1 text (resets vote), keep Opt 2, remove Opt 3, add Opt 4
        $this->factory->updatePoll($poll, 'Nouvelle question', ['Opt 1 modifiée', 'Opt 2', 'Opt 4'], true);

        $this->assertSame('Nouvelle question', $poll->getQuestion());
        $this->assertTrue($poll->isAllowMultiple());
        $this->assertCount(3, $poll->getOptions());

        $options = $poll->getOptions()->getValues();
        $this->assertSame('Opt 1 modifiée', $options[0]->getText());
        $this->assertSame(0, $options[0]->getPosition());
        $this->assertCount(0, $options[0]->getVotes(), 'Votes must be cleared when option text changes');

        $this->assertSame('Opt 2', $options[1]->getText());
        $this->assertSame(1, $options[1]->getPosition());

        $this->assertSame('Opt 4', $options[2]->getText());
        $this->assertSame(2, $options[2]->getPosition());
    }

    #[Test]
    public function updatePollShrinksOptionsList(): void
    {
        $message = new Message();
        $poll = $this->factory->createPoll($message, 'Question', ['Opt 1', 'Opt 2', 'Opt 3', 'Opt 4']);

        $this->factory->updatePoll($poll, 'Question', ['Opt 1', 'Opt 2']);

        $this->assertCount(2, $poll->getOptions());
        $options = $poll->getOptions()->getValues();
        $this->assertSame('Opt 1', $options[0]->getText());
        $this->assertSame('Opt 2', $options[1]->getText());
    }
}
