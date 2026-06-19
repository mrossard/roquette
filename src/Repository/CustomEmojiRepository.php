<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomEmoji;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomEmoji>
 */
class CustomEmojiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomEmoji::class);
    }
}
