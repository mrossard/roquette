<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Service\LinkPreviewService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AllowMockObjectsWithoutExpectations]
class LinkPreviewServiceTest extends TestCase
{
    #[Test]
    public function testGetPreviewInvalidUrl(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects(static::once())->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);
        $result = $service->getPreview('not-a-url');
        static::assertNull($result);
    }

    #[Test]
    public function testGetPreviewPrivateIp(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects(static::once())->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);
        $result = $service->getPreview('http://127.0.0.1/status');
        static::assertNull($result);
    }

    #[Test]
    public function testGetPreviewPrivateIpv6(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects(static::once())->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);
        $result = $service->getPreview('http://[::1]/status');
        static::assertNull($result);
    }

    #[Test]
    public function testGetPreviewPrivateIpv6UniqueLocal(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects(static::once())->method('expiresAfter')->with(300);
                return $callback($item);
            });

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);
        $result = $service->getPreview('http://[fd00::1]/status');
        static::assertNull($result);
    }

    #[Test]
    public function testGetCachedPreviewSuccess(): void
    {
        $item = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn([
            'url' => 'https://example.com',
            'title' => 'Example Domain',
            'description' => 'This is an example',
            'image' => 'https://example.com/logo.png',
            'siteName' => 'Example',
        ]);

        $cache = new class($item) implements
            \Symfony\Contracts\Cache\CacheInterface,
            \Psr\Cache\CacheItemPoolInterface {
            public function __construct(
                private readonly \Psr\Cache\CacheItemInterface $item,
            ) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return null;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function getItem($key): \Psr\Cache\CacheItemInterface
            {
                return $this->item;
            }

            public function getItems(array $keys = []): iterable
            {
                return [];
            }

            public function hasItem($key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function deleteItem($key): bool
            {
                return true;
            }

            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }
        };

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);

        $result = $service->getCachedPreview('https://example.com');
        static::assertNotNull($result);
        static::assertSame('success', $result['status']);
        static::assertSame('Example Domain', $result['preview']['title']);
    }

    #[Test]
    public function testGetCachedPreviewNegative(): void
    {
        $item = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn(null);

        $cache = new class($item) implements
            \Symfony\Contracts\Cache\CacheInterface,
            \Psr\Cache\CacheItemPoolInterface {
            public function __construct(
                private readonly \Psr\Cache\CacheItemInterface $item,
            ) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return null;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function getItem($key): \Psr\Cache\CacheItemInterface
            {
                return $this->item;
            }

            public function getItems(array $keys = []): iterable
            {
                return [];
            }

            public function hasItem($key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function deleteItem($key): bool
            {
                return true;
            }

            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }
        };

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);

        $result = $service->getCachedPreview('https://example.com');
        static::assertNotNull($result);
        static::assertSame('negative', $result['status']);
    }

    #[Test]
    public function testGetCachedPreviewMiss(): void
    {
        $item = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $cache = new class($item) implements
            \Symfony\Contracts\Cache\CacheInterface,
            \Psr\Cache\CacheItemPoolInterface {
            public function __construct(
                private readonly \Psr\Cache\CacheItemInterface $item,
            ) {}

            public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
            {
                return null;
            }

            public function delete(string $key): bool
            {
                return true;
            }

            public function getItem($key): \Psr\Cache\CacheItemInterface
            {
                return $this->item;
            }

            public function getItems(array $keys = []): iterable
            {
                return [];
            }

            public function hasItem($key): bool
            {
                return true;
            }

            public function clear(): bool
            {
                return true;
            }

            public function deleteItem($key): bool
            {
                return true;
            }

            public function deleteItems(array $keys): bool
            {
                return true;
            }

            public function save(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function saveDeferred(\Psr\Cache\CacheItemInterface $item): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }
        };

        $httpClient = $this->createMock(HttpClientInterface::class);
        $service = new LinkPreviewService($cache, $httpClient);

        $result = $service->getCachedPreview('https://example.com');
        static::assertNull($result);
    }
}
