<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ParsedSearchQuery;

final readonly class MessageSearchParser
{
    private const array VALID_FILE_TYPES = ['image', 'video', 'audio', 'pdf'];

    public function parse(string $rawQuery): ParsedSearchQuery
    {
        $rawQuery = trim($rawQuery);
        if ($rawQuery === '') {
            return new ParsedSearchQuery('', '');
        }

        $authorUsername = null;
        $channelName = null;
        $hasFile = null;
        $fileType = null;
        $textQuery = $rawQuery;

        // Parse from:filter
        if (preg_match('/from:([^\s"]+|"[^"]+")/', $textQuery, $matches)) {
            $authorUsername = trim($matches[1], '"@');
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        // Parse in:filter
        if (preg_match('/in:([^\s"]+|"[^"]+")/', $textQuery, $matches)) {
            $channelName = trim($matches[1], '"#');
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        // Parse has:filter
        if (preg_match('/has:([^\s]+)/', $textQuery, $matches)) {
            $hasValue = strtolower($matches[1]);
            $hasFile = true;
            if (in_array($hasValue, self::VALID_FILE_TYPES, strict: true)) {
                $fileType = $hasValue;
            }
            $textQuery = str_replace($matches[0], '', $textQuery);
        }

        $textQuery = trim($textQuery);

        return new ParsedSearchQuery(
            rawQuery: $rawQuery,
            textQuery: $textQuery,
            authorUsername: $authorUsername,
            channelName: $channelName,
            hasFile: $hasFile,
            fileType: $fileType,
        );
    }
}
