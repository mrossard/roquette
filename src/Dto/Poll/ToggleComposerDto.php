<?php

declare(strict_types=1);

namespace App\Dto\Poll;

use Symfony\Component\HttpFoundation\Request;

final readonly class ToggleComposerDto
{
    /**
     * @param list<string> $pollOptions
     */
    public function __construct(
        public bool $open,
        public string $messageValue,
        public string $pollQuestion,
        public array $pollOptions,
        public bool $allowMultiple,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $open = (bool) ($request->query->get('open') ?? $request->request->get('open', false));
        $messageValue = (string) ($request->query->get('message') ?? $request->request->get('message', ''));

        if (!$open) {
            return new self(
                open: false,
                messageValue: $messageValue,
                pollQuestion: '',
                pollOptions: [],
                allowMultiple: false,
            );
        }

        $pollQuestion = (string) ($request->query->get('poll_question') ?? $request->request->get('poll_question', ''));
        $rawOptions = $request->query->all('poll_options');
        if ($rawOptions === []) {
            $rawOptions = $request->request->all('poll_options');
        }

        /** @var list<string> $pollOptions */
        $pollOptions = is_array($rawOptions) ? array_values(array_map('strval', $rawOptions)) : [];
        $allowMultiple = (bool) (
            $request->query->get('allow_multiple') ?? $request->request->get('allow_multiple', false)
        );

        return new self(
            open: true,
            messageValue: $messageValue,
            pollQuestion: $pollQuestion,
            pollOptions: $pollOptions,
            allowMultiple: $allowMultiple,
        );
    }
}
