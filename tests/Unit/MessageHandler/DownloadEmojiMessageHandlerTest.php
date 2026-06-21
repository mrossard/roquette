<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\DownloadEmojiMessage;
use App\MessageHandler\DownloadEmojiMessageHandler;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AllowMockObjectsWithoutExpectations]
class DownloadEmojiMessageHandlerTest extends TestCase
{
    #[Test]
    public function testHandlerDownloadsFileSuccessfully(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $defaultStorage = $this->createMock(FilesystemOperator::class);
        $logger = $this->createMock(LoggerInterface::class);
        $cache = $this->createMock(\Symfony\Contracts\Cache\CacheInterface::class);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('gif_binary_data');

        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'http://example.com/emojis/smile.gif')
            ->willReturn($response);

        // Expect storage checks and write
        $defaultStorage->expects($this->once())->method('has')->with('emojis/smile.gif')->willReturn(false);

        $defaultStorage->expects($this->once())->method('write')->with('emojis/smile.gif', 'gif_binary_data');

        $cache->expects($this->once())->method('delete')->with('emojis_filesystem_list');

        $handler = new DownloadEmojiMessageHandler(
            $httpClient,
            $defaultStorage,
            'http://example.com/emojis/',
            $logger,
            $cache,
        );

        $handler(new DownloadEmojiMessage('smile.gif'));
    }
}
