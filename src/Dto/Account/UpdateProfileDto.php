<?php

declare(strict_types=1);

namespace App\Dto\Account;

use Symfony\Component\HttpFoundation\Request;

final readonly class UpdateProfileDto
{
    /**
     * @param 'auto'|'online'|'away'|'busy'|'offline'|null $statusOverride
     * @param 'fr'|'en'|null $locale
     */
    public function __construct(
        public ?string $displayName = null,
        public ?int $hue = null,
        public ?string $statusOverride = null,
        public ?string $locale = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $rawDisplayName = trim((string) $request->request->get('displayName', ''));
        $displayName = $rawDisplayName !== '' ? $rawDisplayName : null;

        $rawHue = $request->request->get('hue');
        $hue = null;
        if ($rawHue !== null && $rawHue !== '') {
            $hueVal = (int) $rawHue;
            if ($hueVal >= 0 && $hueVal <= 360) {
                $hue = $hueVal;
            }
        }

        $rawStatus = (string) $request->request->get('statusOverride', '');
        $statusOverride = null;
        if (\in_array($rawStatus, ['auto', 'online', 'away', 'busy', 'offline'], true)) {
            $statusOverride = $rawStatus === 'auto' ? null : $rawStatus;
        }

        $rawLocale = (string) $request->request->get('locale', '');
        $locale = \in_array($rawLocale, ['fr', 'en'], true) ? $rawLocale : null;

        return new self(
            displayName: $displayName,
            hue: $hue,
            statusOverride: $statusOverride,
            locale: $locale,
        );
    }
}
