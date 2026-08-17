<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Admin;

use App\Controller\Admin\AdminPaginationTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AdminPaginationTraitTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $this->controller = new class {
            use AdminPaginationTrait;

            public function testGetPage(Request $request): int
            {
                return $this->getPage($request);
            }

            public function testCalculateTotalPages(int $totalItems, int $perPage = self::ADMIN_PER_PAGE): int
            {
                return $this->calculateTotalPages($totalItems, $perPage);
            }
        };
    }

    #[Test]
    public function getPageReturnsDefaultOneWhenMissingOrInvalid(): void
    {
        $request1 = new Request();
        $this->assertSame(1, $this->controller->testGetPage($request1));

        $request2 = new Request(['page' => 0]);
        $this->assertSame(1, $this->controller->testGetPage($request2));

        $request3 = new Request(['page' => -5]);
        $this->assertSame(1, $this->controller->testGetPage($request3));
    }

    #[Test]
    public function getPageReturnsGivenPositivePage(): void
    {
        $request = new Request(['page' => 4]);
        $this->assertSame(4, $this->controller->testGetPage($request));
    }

    #[Test]
    public function calculateTotalPagesComputesCeilCorrectly(): void
    {
        $this->assertSame(0, $this->controller->testCalculateTotalPages(0));
        $this->assertSame(1, $this->controller->testCalculateTotalPages(1));
        $this->assertSame(1, $this->controller->testCalculateTotalPages(25));
        $this->assertSame(2, $this->controller->testCalculateTotalPages(26));
        $this->assertSame(4, $this->controller->testCalculateTotalPages(100));
        $this->assertSame(5, $this->controller->testCalculateTotalPages(101));
    }
}
