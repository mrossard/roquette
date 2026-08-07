<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\IntentClassifier;
use App\Ai\LlmIntentClassifier;
use App\Entity\Channel;
use App\Service\LlmService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class IntentClassifierTest extends TestCase
{
    private function makeChannel(string $name, string $slug): Channel
    {
        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);

        return $channel;
    }

    private function makeClassifier(?string $llmOutput = null): array
    {
        $llm = $this->createMock(LlmService::class);
        if ($llmOutput !== null) {
            $llm->method('generateText')->willReturn($llmOutput);
        }

        return [
            new IntentClassifier(new LlmIntentClassifier($llm, $this->createMock(LoggerInterface::class))),
            $llm,
        ];
    }

    public function testKeywordFastPathResumerSkipsLlm(): void
    {
        [$classifier, $llm] = $this->makeClassifier();
        $channels = [$this->makeChannel('général', 'general')];

        $result = $classifier->classify('Résume le canal général', $channels, 'dm-robot-roquette-mrossard');

        $this->assertSame('resumer', $result['intent']);
        $this->assertSame('general', $result['channelSlug']);
        $llm->expects($this->never())->method('generateText');
    }

    public function testKeywordFastPathResumerWithoutChannelKeepsNull(): void
    {
        [$classifier] = $this->makeClassifier();

        $result = $classifier->classify('Fais-moi une synthèse', [], 'dm-robot-roquette-mrossard');

        $this->assertSame('resumer', $result['intent']);
        $this->assertNull($result['channelSlug']);
    }

    public function testKeywordFastPathSondage(): void
    {
        [$classifier, $llm] = $this->makeClassifier();

        $result = $classifier->classify('Crée un sondage pour le déjeuner', [], 'general');

        $this->assertSame('sondage', $result['intent']);
        $this->assertNull($result['channelSlug']);
        $llm->expects($this->never())->method('generateText');
    }

    public function testLlmClassificationParsedAndValidated(): void
    {
        [$classifier] = $this->makeClassifier('{"intent":"help","channelSlug":null}');

        $result = $classifier->classify('Comment changer mon avatar ?', [], 'general');

        $this->assertSame('help', $result['intent']);
        $this->assertNull($result['channelSlug']);
    }

    public function testLlmClassificationInsideCodeFence(): void
    {
        [$classifier] = $this->makeClassifier("```json\n{\"intent\": \"sondage\", \"channelSlug\": \"general\"}\n```");

        $result = $classifier->classify('Que penses-tu de mon projet ?', [], 'dm-robot-roquette-mrossard');

        $this->assertSame('sondage', $result['intent']);
        $this->assertSame('general', $result['channelSlug']);
    }

    public function testInvalidIntentFallsBackToHelp(): void
    {
        [$classifier] = $this->makeClassifier('{"intent":"banane","channelSlug":null}');

        $result = $classifier->classify('nimporte quoi', [], 'general');

        $this->assertSame('help', $result['intent']);
        $this->assertNull($result['channelSlug']);
    }

    public function testNonJsonLlmOutputFallsBackToHelp(): void
    {
        [$classifier] = $this->makeClassifier('Je ne sais pas répondre à ça.');

        $result = $classifier->classify('Question bizarre', [], 'general');

        $this->assertSame('help', $result['intent']);
    }
}
