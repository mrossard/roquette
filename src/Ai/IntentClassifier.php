<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Channel;
use App\Entity\Workspace;
use App\Service\RobotUserProvider;

/**
 * Classifies a user request into an assistant intent (help / resumer / sondage)
 * and extracts the target channel slug when relevant.
 *
 * Uses a keyword fast-path first to avoid an LLM round-trip on unambiguous
 * requests, then falls back to LlmIntentClassifier for the rest.
 */
final readonly class IntentClassifier
{
    public function __construct(
        private LlmIntentClassifier $llmClassifier,
        private RobotUserProvider $robotUserProvider,
    ) {}

    /**
     * @param list<Channel> $channels
     * @return array{intent: AssistantIntent, channelSlug: string|null}
     */
    public function classify(string $question, array $channels, string $currentChannelSlug, ?Workspace $currentWorkspace = null): array
    {
        $keywordIntent = $this->classifyByKeywords($question);
        if ($keywordIntent !== null) {
            $channelSlug = $keywordIntent === AssistantIntent::Summarize
                ? $this->extractChannelSlug($question, $channels, $currentChannelSlug)
                : null;

            return ['intent' => $keywordIntent, 'channelSlug' => $channelSlug];
        }

        return $this->llmClassifier->classify($question, $channels, $currentChannelSlug, $currentWorkspace)
            ?? ['intent' => AssistantIntent::Help, 'channelSlug' => null];
    }

    private function classifyByKeywords(string $question): ?AssistantIntent
    {
        $normalized = mb_strtolower($question);

        if (preg_match('/\b(?:r[ée]sum[ée]|r[ée]sumer|r[ée]cap|synth[èe]se|synth[èe]tiser)\b|r[ée]sume-moi|fais.{0,30}(?:r[ée]sum[ée]|synth[èe]se)/iu', $normalized) === 1) {
            return AssistantIntent::Summarize;
        }

        if (preg_match('/\b(?:sondage|scrutin|sonder|voter)\b|lance.{0,30}(?:sondage|vote)|cr[ée]e.{0,30}(?:sondage|vote)/iu', $normalized) === 1) {
            return AssistantIntent::Poll;
        }

        return null;
    }

    /**
     * @param list<Channel> $channels
     */
    private function extractChannelSlug(string $question, array $channels, string $currentChannelSlug): ?string
    {
        if (
            !$this->robotUserProvider->isRobotDmChannel($currentChannelSlug)
            && preg_match('/\b(?:ce\s+canal|mon\s+canal|ici)\b/iu', $question) === 1
        ) {
            return $currentChannelSlug;
        }

        $normalizedQuestion = $this->normalizeText($question);

        foreach ($channels as $channel) {
            $slug = $this->normalizeText((string) $channel->getSlug());
            $name = $this->normalizeText((string) $channel->getName());

            if ($this->matchesChannelReference($normalizedQuestion, $slug)) {
                return (string) $channel->getSlug();
            }

            if ($this->matchesChannelReference($normalizedQuestion, $name)) {
                return (string) $channel->getSlug();
            }
        }

        return null;
    }

    private function matchesChannelReference(string $normalizedQuestion, string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        return preg_match('/#?' . preg_quote($candidate, '/') . '(?![\p{L}\p{N}-])/iu', $normalizedQuestion) === 1;
    }

    private function normalizeText(string $text): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);

        return mb_strtolower($ascii !== false ? $ascii : $text);
    }
}
