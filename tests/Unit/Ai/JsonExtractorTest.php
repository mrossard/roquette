<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\JsonExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JsonExtractorTest extends TestCase
{
    #[Test]
    public function extractArrayParsesRawJsonObject(): void
    {
        $input = '{"intent": "resumer", "channelSlug": "general"}';
        $result = JsonExtractor::extractArray($input);

        $this->assertSame(['intent' => 'resumer', 'channelSlug' => 'general'], $result);
    }

    #[Test]
    public function extractArrayParsesRawJsonArray(): void
    {
        $input = '["opt1", "opt2", "opt3"]';
        $result = JsonExtractor::extractArray($input);

        $this->assertSame(['opt1', 'opt2', 'opt3'], $result);
    }

    #[Test]
    public function extractArrayStripsMarkdownCodeFences(): void
    {
        $input = "```json\n{\n  \"intent\": \"sondage\",\n  \"channelSlug\": null\n}\n```";
        $result = JsonExtractor::extractArray($input);

        $this->assertSame(['intent' => 'sondage', 'channelSlug' => null], $result);
    }

    #[Test]
    public function extractArrayStripsGenericMarkdownCodeFences(): void
    {
        $input = "```\n{\n  \"tool\": \"create_poll\",\n  \"parameters\": {\"question\": \"Test ?\"}\n}\n```";
        $result = JsonExtractor::extractArray($input);

        $this->assertSame(
            [
                'tool' => 'create_poll',
                'parameters' => ['question' => 'Test ?'],
            ],
            $result,
        );
    }

    #[Test]
    public function extractArrayExtractsJsonEmbeddedInText(): void
    {
        $input = "Voici le résultat de l'analyse :\n{\"intent\": \"help\", \"channelSlug\": \"dev\"}\nJ'espère que cela aide.";
        $result = JsonExtractor::extractArray($input);

        $this->assertSame(['intent' => 'help', 'channelSlug' => 'dev'], $result);
    }

    #[Test]
    public function extractArrayReturnsNullForInvalidJson(): void
    {
        $this->assertNull(JsonExtractor::extractArray(''));
        $this->assertNull(JsonExtractor::extractArray('Ceci n\'est pas du json'));
        $this->assertNull(JsonExtractor::extractArray('{invalid json: true'));
    }
}
