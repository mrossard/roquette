<?php

declare(strict_types=1);

namespace App\Dto\Account;

use SensitiveParameter;
use Symfony\Component\HttpFoundation\Request;

final readonly class ChangePasswordDto
{
    public function __construct(
        #[SensitiveParameter]
        public string $currentPassword,
        #[SensitiveParameter]
        public string $newPassword,
        #[SensitiveParameter]
        public string $confirmPassword,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            currentPassword: (string) $request->request->get('currentPassword', ''),
            newPassword: (string) $request->request->get('newPassword', ''),
            confirmPassword: (string) $request->request->get('confirmPassword', ''),
        );
    }

    public function isFilled(): bool
    {
        return $this->currentPassword !== '' && $this->newPassword !== '' && $this->confirmPassword !== '';
    }

    public function arePasswordsMatching(): bool
    {
        return hash_equals($this->newPassword, $this->confirmPassword);
    }

    public function isLengthValid(int $min = 6): bool
    {
        return mb_strlen($this->newPassword) >= $min;
    }
}
