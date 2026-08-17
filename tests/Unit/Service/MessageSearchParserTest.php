<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MessageSearchParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageSearchParserTest extends TestCase
{
    private MessageSearchParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MessageSearchParser();
    }

    #[Test]
    public function emptyQueryReturnsEmptyParsedQuery(): void
    {
        $result = $this->parser->parse('');

        $this->assertTrue($result->isEmpty());
        $this->assertFalse($result->hasFilters());
        $this->assertSame('', $result->textQuery);
        $this->assertSame('', $result->rawQuery);
    }

    #[Test]
    public function simpleTextQueryWithoutFilters(): void
    {
        $result = $this->parser->parse('hello world');

        $this->assertFalse($result->isEmpty());
        $this->assertFalse($result->hasFilters());
        $this->assertSame('hello world', $result->textQuery);
        $this->assertNull($result->authorUsername);
        $this->assertNull($result->channelName);
        $this->assertNull($result->hasFile);
        $this->assertNull($result->fileType);
    }

    #[Test]
    public function parsesFromFilter(): void
    {
        $result = $this->parser->parse('from:@alice important meeting');

        $this->assertTrue($result->hasFilters());
        $this->assertSame('important meeting', $result->textQuery);
        $this->assertSame('alice', $result->authorUsername);
        $this->assertNull($result->channelName);
    }

    #[Test]
    public function parsesQuotedFromFilter(): void
    {
        $result = $this->parser->parse('from:"alice cooper" test');

        $this->assertTrue($result->hasFilters());
        $this->assertSame('test', $result->textQuery);
        $this->assertSame('alice cooper', $result->authorUsername);
    }

    #[Test]
    public function parsesInFilter(): void
    {
        $result = $this->parser->parse('in:#general budget');

        $this->assertTrue($result->hasFilters());
        $this->assertSame('budget', $result->textQuery);
        $this->assertSame('general', $result->channelName);
    }

    #[Test]
    public function parsesHasFilterWithSpecificType(): void
    {
        $result = $this->parser->parse('has:pdf invoice');

        $this->assertTrue($result->hasFilters());
        $this->assertSame('invoice', $result->textQuery);
        $this->assertTrue($result->hasFile);
        $this->assertSame('pdf', $result->fileType);
    }

    #[Test]
    public function parsesCombinedFilters(): void
    {
        $result = $this->parser->parse('from:bob in:general has:image screenshot project');

        $this->assertTrue($result->hasFilters());
        $this->assertSame('screenshot project', $result->textQuery);
        $this->assertSame('bob', $result->authorUsername);
        $this->assertSame('general', $result->channelName);
        $this->assertTrue($result->hasFile);
        $this->assertSame('image', $result->fileType);
    }
}
