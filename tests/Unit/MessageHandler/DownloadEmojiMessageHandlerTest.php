<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\DownloadEmojiMessage;
use App\MessageHandler\DownloadEmojiMessageHandler;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class DownloadEmojiMessageHandlerTest extends TestCase
{
    #[Test]
    public function testHandlerDownloadsFileSuccessfully(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $defaultStorage = $this->createMock(FilesystemOperator::class);
        $logger = $this->createMock(LoggerInterface::class);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('gif_binary_data');

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'http://example.com/emojis/smile.gif')
            ->willReturn($response);

        // Expect storage checks and write
        $defaultStorage->expects($this->once())
            ->method('has')
            ->with('emojis/smile.gif')
            ->willReturn(false);

        $defaultStorage->expects($this->once())
            ->method('write')
            ->with('emojis/smile.gif', 'gif_binary_data');

        $handler = new DownloadEmojiMessageHandler(
            $httpClient,
            $defaultStorage,
            'http://example.com/emojis/',
            $logger
        );

        $handler(new DownloadEmojiMessage('smile.gif'));
    }
}
