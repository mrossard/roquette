<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\WorkspaceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class WorkspaceMetricsService
{
    private const array VALID_PERIODS = ['7d', '30d', '90d', '1y'];
    private const int CACHE_TTL_SECONDS = 300; // 5 minutes

    public function __construct(
        private EntityManagerInterface $em,
        private WorkspaceRepository $workspaceRepository,
        private CacheInterface $cache,
    ) {}

    /**
     * @return array{
     *     period: string,
     *     workspaceId: ?int,
     *     workspace: ?Workspace,
     *     allWorkspaces: list<Workspace>,
     *     kpis: array<string, mixed>,
     *     timeline: list<array{date: string, label: string, messages: int, active_users: int}>,
     *     storageBreakdown: list<array{category: string, label: string, bytes: int, files_count: int, percentage: float, color: string}>,
     *     topChannels: list<array{id: int, name: string, slug: string, is_private: bool, is_todo_list: bool, workspace_name: ?string, message_count: int, active_members: int, storage_bytes: int}>,
     *     dormantChannels: list<array{id: int, name: string, slug: string, is_private: bool, is_todo_list: bool, workspace_name: ?string, last_activity_at: ?\DateTimeImmutable, days_inactive: int}>,
     *     aiStats: array{robot_messages: int, polls_created: int, reminders_scheduled: int, total_ai_interactions: int}
     * }
     */
    public function getMetrics(?int $workspaceId = null, string $period = '30d'): array
    {
        $normalizedPeriod = \in_array($period, self::VALID_PERIODS, true) ? $period : '30d';
        $normalizedWsId = $workspaceId !== null && $workspaceId > 0 ? $workspaceId : null;

        $allWorkspaces = $this->workspaceRepository->findBy([], ['name' => 'ASC']);
        $selectedWorkspace = $normalizedWsId !== null ? $this->workspaceRepository->find($normalizedWsId) : null;
        if ($normalizedWsId !== null && $selectedWorkspace === null) {
            $normalizedWsId = null;
        }

        $cacheKey = sprintf('workspace_metrics_%s_%s', $normalizedWsId ?? 'global', $normalizedPeriod);

        /** @var array<string, mixed> $cachedData */
        $cachedData = $this->cache->get($cacheKey, function (ItemInterface $item) use (
            $normalizedWsId,
            $normalizedPeriod,
        ) {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $dates = $this->calculateDateBounds($normalizedPeriod);

            return [
                'kpis' => $this->computeKpis($normalizedWsId, $dates),
                'timeline' => $this->computeTimeline($normalizedWsId, $dates),
                'storageBreakdown' => $this->computeStorageBreakdown($normalizedWsId),
                'topChannels' => $this->computeTopChannels($normalizedWsId, $dates),
                'dormantChannels' => $this->computeDormantChannels($normalizedWsId),
                'aiStats' => $this->computeAiStats($normalizedWsId, $dates),
            ];
        });

        return [
            'period' => $normalizedPeriod,
            'workspaceId' => $normalizedWsId,
            'workspace' => $selectedWorkspace,
            'allWorkspaces' => $allWorkspaces,
            'kpis' => $cachedData['kpis'],
            'timeline' => $cachedData['timeline'],
            'storageBreakdown' => $cachedData['storageBreakdown'],
            'topChannels' => $cachedData['topChannels'],
            'dormantChannels' => $cachedData['dormantChannels'],
            'aiStats' => $cachedData['aiStats'],
        ];
    }

    /**
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable, prevStart: \DateTimeImmutable, daysCount: int}
     */
    private function calculateDateBounds(string $period): array
    {
        $now = new \DateTimeImmutable();
        $daysCount = match ($period) {
            '7d' => 7,
            '90d' => 90,
            '1y' => 365,
            default => 30,
        };

        $start = $now->modify(sprintf('-%d days', $daysCount));
        $prevStart = $start->modify(sprintf('-%d days', $daysCount));

        return [
            'start' => $start,
            'end' => $now,
            'prevStart' => $prevStart,
            'daysCount' => $daysCount,
        ];
    }

    /**
     * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, prevStart: \DateTimeImmutable, daysCount: int} $dates
     * @return array<string, mixed>
     */
    private function computeKpis(?int $workspaceId, array $dates): array
    {
        $conn = $this->getConnection();
        $wsFilter = $workspaceId !== null ? ' AND c.workspace_id = :wsId' : '';
        $params = [
            'start' => $dates['start']->format('Y-m-d H:i:s'),
            'end' => $dates['end']->format('Y-m-d H:i:s'),
            'prevStart' => $dates['prevStart']->format('Y-m-d H:i:s'),
        ];
        if ($workspaceId !== null) {
            $params['wsId'] = $workspaceId;
        }

        $messagesKpi = $this->computeMessagesKpi($conn, $wsFilter, $params);
        $usersKpi = $this->computeUsersKpi($conn, $workspaceId, $wsFilter, $params);
        $storageKpi = $this->computeStorageKpi($conn, $workspaceId, $wsFilter);
        $kanbanKpi = $this->computeKanbanKpi($conn, $workspaceId, $wsFilter);

        $channelsCountSql =
            'SELECT count(c.id) FROM channel c WHERE c.parent_message_id IS NULL'
            . ($workspaceId !== null ? ' AND c.workspace_id = :wsId' : '');
        $totalChannels = (int) $conn->fetchOne(
            $channelsCountSql,
            $workspaceId !== null ? ['wsId' => $workspaceId] : [],
        );

        return array_merge($messagesKpi, $usersKpi, $storageKpi, $kanbanKpi, [
            'total_channels' => $totalChannels,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{total_messages: int, messages_trend: int}
     */
    private function computeMessagesKpi(Connection $conn, string $wsFilter, array $params): array
    {
        $msgSql = "SELECT count(m.id) FROM \"message\" m JOIN channel c ON m.channel_id = c.id WHERE m.created_at >= :start AND m.created_at <= :end{$wsFilter}";
        $totalMessages = (int) $conn->fetchOne($msgSql, $params);

        $prevMsgSql = "SELECT count(m.id) FROM \"message\" m JOIN channel c ON m.channel_id = c.id WHERE m.created_at >= :prevStart AND m.created_at < :start{$wsFilter}";
        $prevTotalMessages = (int) $conn->fetchOne($prevMsgSql, $params);

        $trend = match (true) {
            $prevTotalMessages > 0 => (int) round((($totalMessages - $prevTotalMessages) / $prevTotalMessages) * 100),
            $totalMessages > 0 => 100,
            default => 0,
        };

        return [
            'total_messages' => $totalMessages,
            'messages_trend' => $trend,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{active_users: int, total_users: int, active_users_ratio: float}
     */
    private function computeUsersKpi(Connection $conn, ?int $workspaceId, string $wsFilter, array $params): array
    {
        $userParams = array_merge($params, ['robot' => User::ROBOT_USERNAME]);
        $activeUsersSql = "SELECT count(DISTINCT m.author_id) FROM \"message\" m JOIN channel c ON m.channel_id = c.id JOIN \"user\" u ON m.author_id = u.id WHERE m.created_at >= :start AND m.created_at <= :end AND u.username != :robot{$wsFilter}";
        $activeUsers = (int) $conn->fetchOne($activeUsersSql, $userParams);

        $totalUsers = $this->calculateTotalUsers($conn, $workspaceId);
        $activeUsersRatio = $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0.0;

        return [
            'active_users' => $activeUsers,
            'total_users' => $totalUsers,
            'active_users_ratio' => $activeUsersRatio,
        ];
    }

    private function calculateTotalUsers(Connection $conn, ?int $workspaceId): int
    {
        if ($workspaceId === null) {
            return (int) $conn->fetchOne('SELECT count(id) FROM "user" WHERE username != :robot', [
                'robot' => User::ROBOT_USERNAME,
            ]);
        }

        $totalUsers = (int) $conn->fetchOne('SELECT count(user_id) FROM workspace_user WHERE workspace_id = :wsId', [
            'wsId' => $workspaceId,
        ]);
        if ($totalUsers > 0) {
            return $totalUsers;
        }

        return max(
            1,
            (int) $conn->fetchOne('SELECT count(id) FROM "user" WHERE username != :robot', [
                'robot' => User::ROBOT_USERNAME,
            ]),
        );
    }

    /**
     * @return array{total_storage_bytes: int, total_files: int}
     */
    private function computeStorageKpi(Connection $conn, ?int $workspaceId, string $wsFilter): array
    {
        $storageSql = "SELECT COALESCE(SUM(m.file_size), 0) as total_bytes, count(m.id) as total_files FROM \"message\" m JOIN channel c ON m.channel_id = c.id WHERE m.file_path IS NOT NULL{$wsFilter}";
        $row = $conn->fetchAssociative($storageSql, $workspaceId !== null ? ['wsId' => $workspaceId] : []);
        $storageRow = \is_array($row) ? $row : ['total_bytes' => 0, 'total_files' => 0];

        return [
            'total_storage_bytes' => (int) $storageRow['total_bytes'],
            'total_files' => (int) $storageRow['total_files'],
        ];
    }

    /**
     * @return array{kanban_total_tasks: int, kanban_completed_tasks: int, kanban_completion_rate: float}
     */
    private function computeKanbanKpi(Connection $conn, ?int $workspaceId, string $wsFilter): array
    {
        $kanbanSql = "SELECT count(m.id) as total_tasks, count(CASE WHEN m.is_completed = true THEN 1 END) as completed_tasks FROM \"message\" m JOIN channel c ON m.channel_id = c.id WHERE m.kanban_column_id IS NOT NULL{$wsFilter}";
        $row = $conn->fetchAssociative($kanbanSql, $workspaceId !== null ? ['wsId' => $workspaceId] : []);
        $kanbanRow = \is_array($row) ? $row : ['total_tasks' => 0, 'completed_tasks' => 0];

        $kanbanTotalTasks = (int) $kanbanRow['total_tasks'];
        $kanbanCompletedTasks = (int) $kanbanRow['completed_tasks'];
        $kanbanCompletionRate = $kanbanTotalTasks > 0
            ? round(($kanbanCompletedTasks / $kanbanTotalTasks) * 100, 1)
            : 0.0;

        return [
            'kanban_total_tasks' => $kanbanTotalTasks,
            'kanban_completed_tasks' => $kanbanCompletedTasks,
            'kanban_completion_rate' => $kanbanCompletionRate,
        ];
    }

    /**
     * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, prevStart: \DateTimeImmutable, daysCount: int} $dates
     * @return list<array{date: string, label: string, messages: int, active_users: int}>
     */
    private function computeTimeline(?int $workspaceId, array $dates): array
    {
        $conn = $this->getConnection();
        $wsFilter = $workspaceId !== null ? ' AND c.workspace_id = :wsId' : '';
        $params = [
            'start' => $dates['start']->format('Y-m-d 00:00:00'),
            'end' => $dates['end']->format('Y-m-d 23:59:59'),
        ];
        if ($workspaceId !== null) {
            $params['wsId'] = $workspaceId;
        }

        $sql = "SELECT to_char(date_trunc('day', m.created_at), 'YYYY-MM-DD') AS day, count(m.id) AS msg_count, count(DISTINCT m.author_id) AS usr_count FROM \"message\" m JOIN channel c ON m.channel_id = c.id WHERE m.created_at >= :start AND m.created_at <= :end{$wsFilter} GROUP BY day ORDER BY day ASC";

        $rows = $conn->fetchAllAssociative($sql, $params);
        $indexedRows = [];
        foreach ($rows as $row) {
            $indexedRows[(string) $row['day']] = [
                'messages' => (int) $row['msg_count'],
                'active_users' => (int) $row['usr_count'],
            ];
        }

        $timeline = [];
        $current = $dates['start'];
        $endDate = $dates['end'];

        while ($current <= $endDate) {
            $dateStr = $current->format('Y-m-d');
            $data = $indexedRows[$dateStr] ?? ['messages' => 0, 'active_users' => 0];

            $timeline[] = [
                'date' => $dateStr,
                'label' => $current->format('d/m'),
                'messages' => $data['messages'],
                'active_users' => $data['active_users'],
            ];

            $current = $current->modify('+1 day');
        }

        return $timeline;
    }

    /**
     * @return list<array{category: string, label: string, bytes: int, files_count: int, percentage: float, color: string}>
     */
    private function computeStorageBreakdown(?int $workspaceId): array
    {
        $conn = $this->getConnection();
        $wsFilter = $workspaceId !== null ? ' AND c.workspace_id = :wsId' : '';
        $params = $workspaceId !== null ? ['wsId' => $workspaceId] : [];

        $sql = "SELECT 
            CASE 
                WHEN m.mime_type LIKE 'image/%' THEN 'image'
                WHEN m.mime_type LIKE 'video/%' THEN 'video'
                WHEN m.mime_type LIKE 'audio/%' THEN 'audio'
                WHEN m.mime_type LIKE '%pdf%' OR m.mime_type LIKE '%document%' OR m.mime_type LIKE '%text%' OR m.mime_type LIKE '%presentation%' OR m.mime_type LIKE '%sheet%' THEN 'document'
                ELSE 'other'
            END AS category,
            count(m.id) AS file_count,
            COALESCE(SUM(m.file_size), 0) AS total_bytes
        FROM \"message\" m
        JOIN channel c ON m.channel_id = c.id
        WHERE m.file_path IS NOT NULL{$wsFilter}
        GROUP BY category";

        $rows = $conn->fetchAllAssociative($sql, $params);
        $totalBytesAll = 0;
        $categoryData = [];

        foreach ($rows as $row) {
            $cat = (string) $row['category'];
            $bytes = (int) $row['total_bytes'];
            $count = (int) $row['file_count'];
            $totalBytesAll += $bytes;
            $categoryData[$cat] = ['bytes' => $bytes, 'count' => $count];
        }

        $definitions = [
            'image' => ['label' => 'Images', 'color' => '#3b82f6'],
            'document' => ['label' => 'Documents / PDF', 'color' => '#10b981'],
            'video' => ['label' => 'Vidéos', 'color' => '#8b5cf6'],
            'audio' => ['label' => 'Audio', 'color' => '#f59e0b'],
            'other' => ['label' => 'Autres', 'color' => '#6b7280'],
        ];

        $breakdown = [];
        foreach ($definitions as $key => $meta) {
            $bytes = $categoryData[$key]['bytes'] ?? 0;
            $count = $categoryData[$key]['count'] ?? 0;
            $percentage = $totalBytesAll > 0 ? round(($bytes / $totalBytesAll) * 100, 1) : 0.0;

            $breakdown[] = [
                'category' => $key,
                'label' => $meta['label'],
                'bytes' => $bytes,
                'files_count' => $count,
                'percentage' => $percentage,
                'color' => $meta['color'],
            ];
        }

        return $breakdown;
    }

    /**
     * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, prevStart: \DateTimeImmutable, daysCount: int} $dates
     * @return list<array{id: int, name: string, slug: string, is_private: bool, is_todo_list: bool, workspace_name: ?string, message_count: int, active_members: int, storage_bytes: int}>
     */
    private function computeTopChannels(?int $workspaceId, array $dates): array
    {
        $conn = $this->getConnection();
        $wsFilter = $workspaceId !== null ? ' AND c.workspace_id = :wsId' : '';
        $params = [
            'start' => $dates['start']->format('Y-m-d H:i:s'),
        ];
        if ($workspaceId !== null) {
            $params['wsId'] = $workspaceId;
        }

        $sql = "SELECT 
            c.id,
            c.name,
            c.slug,
            c.is_private,
            c.is_todo_list,
            w.name AS workspace_name,
            count(m.id) AS message_count,
            count(DISTINCT m.author_id) AS active_members,
            COALESCE(SUM(m.file_size), 0) AS storage_bytes
        FROM channel c
        LEFT JOIN \"workspace\" w ON c.workspace_id = w.id
        JOIN \"message\" m ON m.channel_id = c.id
        WHERE m.created_at >= :start{$wsFilter}
        GROUP BY c.id, c.name, c.slug, c.is_private, c.is_todo_list, w.name
        ORDER BY message_count DESC
        LIMIT 10";

        $rows = $conn->fetchAllAssociative($sql, $params);

        return array_map(static fn(array $r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'is_private' => (bool) $r['is_private'],
            'is_todo_list' => (bool) $r['is_todo_list'],
            'workspace_name' => $r['workspace_name'] !== null ? (string) $r['workspace_name'] : null,
            'message_count' => (int) $r['message_count'],
            'active_members' => (int) $r['active_members'],
            'storage_bytes' => (int) $r['storage_bytes'],
        ], $rows);
    }

    /**
     * @return list<array{id: int, name: string, slug: string, is_private: bool, is_todo_list: bool, workspace_name: ?string, last_activity_at: ?\DateTimeImmutable, days_inactive: int}>
     */
    private function computeDormantChannels(?int $workspaceId): array
    {
        $conn = $this->getConnection();
        $wsFilter = $workspaceId !== null ? ' AND c.workspace_id = :wsId' : '';
        $threshold = new \DateTimeImmutable()
            ->modify('-30 days')
            ->format('Y-m-d H:i:s');
        $params = ['threshold' => $threshold];
        if ($workspaceId !== null) {
            $params['wsId'] = $workspaceId;
        }

        $sql = "SELECT 
            c.id,
            c.name,
            c.slug,
            c.is_private,
            c.is_todo_list,
            w.name AS workspace_name,
            MAX(m.created_at) AS last_message_at,
            c.created_at AS channel_created_at
        FROM channel c
        LEFT JOIN \"workspace\" w ON c.workspace_id = w.id
        LEFT JOIN \"message\" m ON m.channel_id = c.id
        WHERE c.parent_message_id IS NULL{$wsFilter}
        GROUP BY c.id, c.name, c.slug, c.is_private, c.is_todo_list, w.name, c.created_at
        HAVING MAX(m.created_at) < :threshold OR (MAX(m.created_at) IS NULL AND c.created_at < :threshold)
        ORDER BY last_message_at ASC NULLS FIRST
        LIMIT 10";

        $rows = $conn->fetchAllAssociative($sql, $params);
        $now = new \DateTimeImmutable();

        return array_map(static function (array $r) use ($now) {
            $dateStr = $r['last_message_at'] ?? $r['channel_created_at'];
            $lastDate = $dateStr !== null ? new \DateTimeImmutable((string) $dateStr) : null;
            $daysInactive = $lastDate !== null ? (int) $now->diff($lastDate)->format('%a') : 30;

            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
                'is_private' => (bool) $r['is_private'],
                'is_todo_list' => (bool) $r['is_todo_list'],
                'workspace_name' => $r['workspace_name'] !== null ? (string) $r['workspace_name'] : null,
                'last_activity_at' => $lastDate,
                'days_inactive' => $daysInactive,
            ];
        }, $rows);
    }

    /**
     * @param array{start: \DateTimeImmutable, end: \DateTimeImmutable, prevStart: \DateTimeImmutable, daysCount: int} $dates
     * @return array{robot_messages: int, polls_created: int, reminders_scheduled: int, total_ai_interactions: int}
     */
    private function computeAiStats(?int $workspaceId, array $dates): array
    {
        $conn = $this->getConnection();
        $wsFilter = $workspaceId !== null ? ' AND c.workspace_id = :wsId' : '';
        $params = [
            'start' => $dates['start']->format('Y-m-d H:i:s'),
            'robot' => User::ROBOT_USERNAME,
        ];
        if ($workspaceId !== null) {
            $params['wsId'] = $workspaceId;
        }

        $robotSql = "SELECT count(m.id) FROM \"message\" m JOIN channel c ON m.channel_id = c.id JOIN \"user\" u ON m.author_id = u.id WHERE m.created_at >= :start AND u.username = :robot{$wsFilter}";
        $robotMessages = (int) $conn->fetchOne($robotSql, $params);

        $pollSql = "SELECT count(p.id) FROM poll p JOIN \"message\" m ON m.poll_id = p.id JOIN channel c ON m.channel_id = c.id WHERE m.created_at >= :start{$wsFilter}";
        $pollsCreated = (int) $conn->fetchOne(
            $pollSql,
            $workspaceId !== null
                ? ['start' => $params['start'], 'wsId' => $workspaceId]
                : ['start' => $params['start']],
        );

        $reminderSql = "SELECT count(r.id) FROM reminder r JOIN channel c ON r.channel_id = c.id WHERE r.created_at >= :start{$wsFilter}";
        $remindersScheduled = (int) $conn->fetchOne(
            $reminderSql,
            $workspaceId !== null
                ? ['start' => $params['start'], 'wsId' => $workspaceId]
                : ['start' => $params['start']],
        );

        return [
            'robot_messages' => $robotMessages,
            'polls_created' => $pollsCreated,
            'reminders_scheduled' => $remindersScheduled,
            'total_ai_interactions' => $robotMessages + $pollsCreated + $remindersScheduled,
        ];
    }

    private function getConnection(): Connection
    {
        return $this->em->getConnection();
    }
}
