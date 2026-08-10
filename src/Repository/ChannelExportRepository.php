<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChannelExport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelExport>
 */
class ChannelExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelExport::class);
    }

    /**
     * @return ChannelExport[]
     */
    public function findPaginated(int $page, int $perPage = 25): array
    {
        $page = max(1, min($page, 10000));
        $perPage = max(1, min($perPage, 100));

        return $this->findBy([], ['createdAt' => 'DESC'], $perPage, ($page - 1) * $perPage);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }
}
