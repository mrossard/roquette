<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;

final class DocChunker
{
    private const string DOC_PATH = '/DOC_UTILISATEUR.md';

    /**
     * @return TextDocument[]
     */
    public function chunk(string $projectDir): array
    {
        $path = $projectDir . self::DOC_PATH;
        if (!file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);
        $sections = preg_split('/^## /m', $content, -1, PREG_SPLIT_NO_EMPTY);

        $documents = [];
        foreach ($sections as $section) {
            $lines = explode("\n", trim($section));
            $title = array_shift($lines);
            $body = trim(implode("\n", $lines));

            $metadata = new Metadata([
                '_title' => $title,
                '_text' => $body,
            ]);

            $documents[] = new TextDocument($this->generateUuid(), $body, $metadata);
        }

        return $documents;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
