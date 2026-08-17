<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\HelpStreamPublisher;
use App\Ai\StreamResponseCoordinator;
use App\Service\MessageFormatter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
final class StreamResponseCoordinatorTest extends TestCase
{
    public function testStreamAndPublishThrottlesCorrectly(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $formatter = $this->createStub(MessageFormatter::class);
        $formatter->method('format')->willReturnCallback(static fn(string $text) => "<p>{$text}</p>");
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<div>rendered</div>');

        $mercurePublisher = new \App\Service\MercurePublisher(
            $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class),
            'roquette',
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );
        $publisher = new HelpStreamPublisher($hub, $formatter, $twig, $mercurePublisher);
        $coordinator = new StreamResponseCoordinator($publisher);

        $chunks = ['One ', 'Two ', 'Three ', 'Four ', 'Five ', 'Six '];
        $generator = (static function () use ($chunks) {
            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        })();

        // 6 chunks with burst=3, mod=3:
        // Chunk 1: published (<=3)
        // Chunk 2: published (<=3)
        // Chunk 3: published (<=3)
        // Chunk 4: skipped (not % 3)
        // Chunk 5: skipped (not % 3)
        // Chunk 6: published (6 % 3 === 0)
        // Final flush: published (with token)
        // Total Mercure publishes = 5
        $hub->expects(static::exactly(5))->method('publish')->with(static::isInstanceOf(Update::class));

        $token = 'token-abc';
        $result = $coordinator->streamAndPublish(
            $generator,
            'topic/personal',
            'help-123',
            '**Prefix:** ',
            'general',
            static fn(): string => $token,
        );

        static::assertSame('One Two Three Four Five Six ', $result['text']);
        static::assertSame(6, $result['chunkCount']);
    }
}
