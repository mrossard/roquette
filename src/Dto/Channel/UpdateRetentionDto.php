<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use Symfony\Component\HttpFoundation\Request;

final readonly class UpdateRetentionDto
{
    public function __construct(
        public ?int $retentionMonths,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $retention = $request->request->get('messageRetentionMonths');
        if ($retention === null || $retention === '') {
            return new self(null);
        }

        $val = (int) $retention;

        return new self($val <= 0 ? null : $val);
    }
}
