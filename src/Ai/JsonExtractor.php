<?php

declare(strict_types=1);

namespace App\Ai;

final class JsonExtractor
{
    /**
     * Extracts and decodes a JSON array or object from a text string that may contain markdown code fences.
     *
     * @return array<string, mixed>|list<mixed>|null
     */
    public static function extractArray(string $text): ?array
    {
        $trimmed = trim($text);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        if (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
            if (!preg_match('/\{[\s\S]*\}|\[[\s\S]*\]/', $trimmed, $matches)) {
                return null;
            }

            $trimmed = $matches[0];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);

            return \is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
