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
        $voteRepo = $this->entityManager->getRepository(PollVote::class);
        $existingVote = $voteRepo->findOneBy([
            'option' => $option,
            'user' => $user,
        ]);

        if (!$poll->isAllowMultiple()) {
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
                $newVote = new PollVote();
                $newVote->setUser($user);
                $newVote->setOption($option);
                $option->addVote($newVote);
                $this->entityManager->persist($newVote);
            }
        } else {
            if ($existingVote) {
                $option->removeVote($existingVote);
                $this->entityManager->remove($existingVote);
            } else {
                $newVote = new PollVote();
                $newVote->setUser($user);
                $newVote->setOption($option);
                $option->addVote($newVote);
                $this->entityManager->persist($newVote);
            }
        }

        $this->entityManager->flush();
//        $this->entityManager->refresh($poll->getMessage());
//        $this->entityManager->refresh($poll);
//        foreach ($poll->getOptions() as $opt) {
//            $this->entityManager->refresh($opt);
//        }
    }
}
