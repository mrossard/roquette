<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PollOption;
use App\Entity\PollVote;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class PollManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function toggleVote(PollOption $option, User $user): void
    {
        $poll = $option->getPoll();
        if (!$poll->isAllowMultiple()) {
            $this->toggleSingleChoiceVote($option, $user);
            $this->entityManager->flush();
            return;
        }

        $this->toggleMultipleChoiceVote($option, $user);
        $this->entityManager->flush();
    }

    private function toggleSingleChoiceVote(PollOption $option, User $user): void
    {
        $poll = $option->getPoll();
        $voteRepo = $this->entityManager->getRepository(PollVote::class);
        $userVotes = $voteRepo
            ->createQueryBuilder('v')
            ->join('v.option', 'o')
            ->where('o.poll = :poll')
            ->andWhere('v.user = :user')
            ->setParameter('poll', $poll)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $wasVotedOnTarget = false;
        foreach ($userVotes as $vote) {
            if ($vote->getOption()->getId() === $option->getId()) {
                $wasVotedOnTarget = true;
            }
            $vote->getOption()->removeVote($vote);
            $this->entityManager->remove($vote);
        }

        if (!$wasVotedOnTarget) {
            $this->createVote($option, $user);
        }
    }

    private function toggleMultipleChoiceVote(PollOption $option, User $user): void
    {
        $voteRepo = $this->entityManager->getRepository(PollVote::class);
        $existingVote = $voteRepo->findOneBy([
            'option' => $option,
            'user' => $user,
        ]);

        if ($existingVote !== null) {
            $option->removeVote($existingVote);
            $this->entityManager->remove($existingVote);
            return;
        }

        $this->createVote($option, $user);
    }

    private function createVote(PollOption $option, User $user): void
    {
        $newVote = new PollVote();
        $newVote->setUser($user);
        $newVote->setOption($option);
        $option->addVote($newVote);
        $this->entityManager->persist($newVote);
    }
}
