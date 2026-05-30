<?php

namespace App\Tests\Unit\Service;

use App\Service\LinkPreviewService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class LinkPreviewServiceTest extends TestCase
{
    #[Test]
    public function testGetPreviewInvalidUrl(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });

        $service = new LinkPreviewService($cache);
        $result = $service->getPreview('not-a-url');
        $this->assertNull($result);
    }

    #[Test]
    public function testGetPreviewPrivateIp(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function ($key, $callback) {
            $item = $this->createMock(ItemInterface::class);
            return $callback($item);
        });

        $service = new LinkPreviewService($cache);
        $result = $service->getPreview('http://127.0.0.1/status');
        $this->assertNull($result);
    }
}
