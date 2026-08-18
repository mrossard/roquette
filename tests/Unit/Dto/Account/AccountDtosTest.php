<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto\Account;

use App\Dto\Account\ChangePasswordDto;
use App\Dto\Account\UpdateNotificationPreferencesDto;
use App\Dto\Account\UpdateProfileDto;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AccountDtosTest extends TestCase
{
    #[Test]
    public function updateProfileDtoParsesRequestCorrectly(): void
    {
        $request = new Request([], [
            'displayName' => '  John Doe  ',
            'hue' => '180',
            'statusOverride' => 'away',
            'locale' => 'fr',
        ]);

        $dto = UpdateProfileDto::fromRequest($request);

        $this->assertSame('John Doe', $dto->displayName);
        $this->assertSame(180, $dto->hue);
        $this->assertSame('away', $dto->statusOverride);
        $this->assertSame('fr', $dto->locale);
    }

    #[Test]
    public function updateProfileDtoHandlesEmptyAndInvalidValues(): void
    {
        $request = new Request([], [
            'displayName' => '   ',
            'hue' => '999',
            'statusOverride' => 'invalid_status',
            'locale' => 'es',
        ]);

        $dto = UpdateProfileDto::fromRequest($request);

        $this->assertNull($dto->displayName);
        $this->assertNull($dto->hue);
        $this->assertNull($dto->statusOverride);
        $this->assertNull($dto->locale);
    }

    #[Test]
    public function changePasswordDtoValidationMethods(): void
    {
        $request = new Request([], [
            'currentPassword' => 'oldsecret',
            'newPassword' => 'newsecret123',
            'confirmPassword' => 'newsecret123',
        ]);

        $dto = ChangePasswordDto::fromRequest($request);

        $this->assertTrue($dto->isFilled());
        $this->assertTrue($dto->arePasswordsMatching());
        $this->assertTrue($dto->isLengthValid(6));

        $mismatch = new ChangePasswordDto('old', 'secret1', 'secret2');
        $this->assertFalse($mismatch->arePasswordsMatching());

        $short = new ChangePasswordDto('old', '123', '123');
        $this->assertFalse($short->isLengthValid(6));
    }

    #[Test]
    public function updateNotificationPreferencesDtoParsesBoolean(): void
    {
        $request = new Request([], [
            'mentionNotificationsEnabled' => '1',
        ]);

        $dto = UpdateNotificationPreferencesDto::fromRequest($request);
        $this->assertTrue($dto->mentionNotificationsEnabled);

        $emptyRequest = new Request();
        $dtoEmpty = UpdateNotificationPreferencesDto::fromRequest($emptyRequest);
        $this->assertFalse($dtoEmpty->mentionNotificationsEnabled);
    }
}
