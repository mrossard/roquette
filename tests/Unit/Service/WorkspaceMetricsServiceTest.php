<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use App\Service\WorkspaceMetricsService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AllowMockObjectsWithoutExpectations]
final class WorkspaceMetricsServiceTest extends TestCase
{
    #[Test]
    public function itReturnsMetricsStructureWithExpectedKeys(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $workspaceRepo = $this->createStub(WorkspaceRepository::class);
        $cache = $this->createStub(CacheInterface::class);
        $conn = $this->createStub(Connection::class);

        $em->method('getConnection')->willReturn($conn);

        $workspace = new Workspace();
        $workspace->setName('Test Workspace');
        $workspace->setSlug('test-workspace');

        $workspaceRepo->method('findBy')->willReturn([$workspace]);
        $workspaceRepo->method('find')->willReturn($workspace);

        $cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createStub(ItemInterface::class);
                return $callback($item);
            });

        // Mock DB returns
        $conn->method('fetchOne')->willReturn(10);
        $conn->method('fetchAssociative')->willReturn([
            'total_bytes' => 2048,
            'total_files' => 5,
            'total_tasks' => 8,
            'completed_tasks' => 4,
        ]);
        $conn->method('fetchAllAssociative')->willReturn([]);

        $service = new WorkspaceMetricsService($em, $workspaceRepo, $cache);
        $result = $service->getMetrics(1, '30d');

        $this->assertSame('30d', $result['period']);
        $this->assertSame(1, $result['workspaceId']);
        $this->assertSame($workspace, $result['workspace']);
        $this->assertArrayHasKey('kpis', $result);
        $this->assertArrayHasKey('timeline', $result);
        $this->assertArrayHasKey('storageBreakdown', $result);
        $this->assertArrayHasKey('topChannels', $result);
        $this->assertArrayHasKey('dormantChannels', $result);
        $this->assertArrayHasKey('aiStats', $result);
    }

    #[Test]
    public function itFallsBackToDefaultPeriodWhenInvalid(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $workspaceRepo = $this->createStub(WorkspaceRepository::class);
        $cache = $this->createStub(CacheInterface::class);
        $conn = $this->createStub(Connection::class);

        $em->method('getConnection')->willReturn($conn);
        $workspaceRepo->method('findBy')->willReturn([]);

        $cache
            ->method('get')
            ->willReturn([
                'kpis' => [],
                'timeline' => [],
                'storageBreakdown' => [],
                'topChannels' => [],
                'dormantChannels' => [],
                'aiStats' => [
                    'robot_messages' => 0,
                    'polls_created' => 0,
                    'reminders_scheduled' => 0,
                    'total_ai_interactions' => 0,
                ],
            ]);

        $service = new WorkspaceMetricsService($em, $workspaceRepo, $cache);
        $result = $service->getMetrics(null, 'invalid_period');

        $this->assertSame('30d', $result['period']);
        $this->assertNull($result['workspaceId']);
    }
}
