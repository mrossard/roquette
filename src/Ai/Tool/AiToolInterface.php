<?php

declare(strict_types=1);

namespace App\Ai\Tool;

interface AiToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return array<string, mixed> JSON Schema describing the tool parameters (object + properties + required).
     */
    public function getParametersSchema(): array;
}
