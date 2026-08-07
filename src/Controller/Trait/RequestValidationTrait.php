<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Request;

trait RequestValidationTrait
{
    /**
     * Checks if a POST request exceeded the PHP post_max_size configuration.
     * Symfony returns empty request and files parameters if post_max_size is exceeded.
     */
    private function isPostMaxSizeExceeded(Request $request): bool
    {
        return (
            $request->isMethod('POST')
            && count($request->request) === 0
            && count($request->files) === 0
            && (int) $request->headers->get('CONTENT_LENGTH', 0) > 0
        );
    }

    /**
     * Extracts non-empty poll options from a request.
     *
     * @return string[]
     */
    private function parsePollOptions(Request $request): array
    {
        $optionsData = $request->request->all()['poll_options'] ?? [];
        if (!is_array($optionsData)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $optionsData), static fn($val) => $val !== ''));
    }
}
