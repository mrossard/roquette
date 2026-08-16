<?php

declare(strict_types=1);

namespace App\Dto\Message;

use Symfony\Component\HttpFoundation\Request;

final readonly class EditMessageDto
{
    /**
     * @param list<string> $pollOptions
     */
    public function __construct(
        public string $content = '',
        public ?string $pollQuestion = null,
        public array $pollOptions = [],
        public bool $allowMultiple = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $content = $request->request->getString('content');
        $pollQuestion = $request->request->get('poll_question');
        $rawOptions = $request->request->all('poll_options');
        $pollOptions = is_array($rawOptions)
            ? array_values(array_filter(array_map('trim', $rawOptions), static fn($v) => $v !== ''))
            : [];
        $allowMultiple = $request->request->getBoolean('allow_multiple');

        return new self(
            content: $content,
            pollQuestion: $pollQuestion !== null ? (string) $pollQuestion : null,
            pollOptions: $pollOptions,
            allowMultiple: $allowMultiple,
        );
    }
}
