<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LinkPreviewService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
}
