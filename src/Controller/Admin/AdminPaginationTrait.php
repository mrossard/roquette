<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;

trait AdminPaginationTrait
{
    protected const int ADMIN_PER_PAGE = 25;

    protected function getPage(Request $request): int
    {
        return max(1, $request->query->getInt('page', 1));
    }

    protected function calculateTotalPages(int $totalItems, int $perPage = self::ADMIN_PER_PAGE): int
    {
        return (int) ceil($totalItems / max(1, $perPage));
    }
}
