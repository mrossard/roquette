<?php

declare(strict_types=1);

namespace App\Dto\Poll;

use Symfony\Component\HttpFoundation\Request;

final readonly class CreatePollDto
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $question,
        public array $options,
        public bool $allowMultiple = false,
    ) {}

    public static function fromRequest(Request $request): ?self
    {
        $question = trim((string) $request->request->get('poll_question', ''));
        if ($question === '') {
            return null;
        }

        $rawOptions = $request->request->all('poll_options');
        $options = is_array($rawOptions)
            ? array_values(array_filter(array_map('trim', $rawOptions), static fn($v) => $v !== ''))
            : [];

        return new self(
            question: $question,
            options: $options,
            allowMultiple: $request->request->getBoolean('allow_multiple'),
        );
    }
}
