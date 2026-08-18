<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\PollOption;

class PollFactory
{
    public const int MIN_OPTIONS_COUNT = 2;
    public const string ERROR_EMPTY_QUESTION = 'La question du sondage ne peut pas être vide.';
    public const string ERROR_MIN_OPTIONS = 'Un sondage requiert au moins 2 options.';

    /**
     * Checks if the given list of options meets the minimum count requirement.
     *
     * @param array<int, string>|null $options
     */
    public function hasValidOptions(?array $options): bool
    {
        return $options !== null && count($options) >= self::MIN_OPTIONS_COUNT;
    }

    /**
     * Validates poll question and options.
     *
     * @param array<int, string>|null $options
     * @throws \InvalidArgumentException
     */
    public function validate(?string $question, ?array $options): void
    {
        if ($question === null || trim($question) === '') {
            throw new \InvalidArgumentException(self::ERROR_EMPTY_QUESTION);
        }

        if (!$this->hasValidOptions($options)) {
            throw new \InvalidArgumentException(self::ERROR_MIN_OPTIONS);
        }
    }

    /**
     * Creates and attaches a new Poll to the given Message.
     *
     * @param array<int, string> $options
     */
    public function createPoll(Message $message, string $question, array $options, bool $allowMultiple = false): Poll
    {
        $this->validate($question, $options);

        $poll = new Poll();
        $poll->setQuestion(trim($question));
        $poll->setAllowMultiple($allowMultiple);
        $poll->setMessage($message);
        $message->setPoll($poll);

        $position = 0;
        foreach ($options as $optionText) {
            $opt = new PollOption();
            $opt->setText(trim($optionText));
            $opt->setPosition($position++);
            $poll->addOption($opt);
        }

        return $poll;
    }

    /**
     * Updates an existing Poll and syncs its options.
     *
     * @param array<int, string> $optionsData
     */
    public function updatePoll(Poll $poll, string $question, array $optionsData, bool $allowMultiple = false): void
    {
        $this->validate($question, $optionsData);

        $poll->setQuestion(trim($question));
        $poll->setAllowMultiple($allowMultiple);

        $existingOptions = $poll->getOptions()->getValues();
        $position = 0;

        foreach ($optionsData as $idx => $optText) {
            $trimmedText = trim($optText);
            if (array_key_exists($idx, $existingOptions)) {
                $existingOpt = $existingOptions[$idx];
                if ($existingOpt->getText() !== $trimmedText) {
                    $existingOpt->setText($trimmedText);
                    $existingOpt->getVotes()->clear();
                }
                $existingOpt->setPosition($position++);
                continue;
            }

            $newOption = new PollOption();
            $newOption->setText($trimmedText);
            $newOption->setPosition($position++);
            $poll->addOption($newOption);
        }

        for ($i = count($optionsData); $i < count($existingOptions); $i++) {
            $poll->removeOption($existingOptions[$i]);
        }
    }
}
