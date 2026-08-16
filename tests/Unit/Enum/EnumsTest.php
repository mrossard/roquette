<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ModerationStatus;
use App\Enum\TaskPriority;
use App\Enum\UserLocale;
use App\Enum\UserPresenceStatus;
use App\Enum\UserTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    #[Test]
    public function userPresenceStatusValues(): void
    {
        $this->assertSame('auto', UserPresenceStatus::AUTO->value);
        $this->assertSame('online', UserPresenceStatus::ONLINE->value);
        $this->assertSame('away', UserPresenceStatus::AWAY->value);
        $this->assertSame('busy', UserPresenceStatus::BUSY->value);
        $this->assertSame('offline', UserPresenceStatus::OFFLINE->value);
    }

    #[Test]
    public function userThemeValues(): void
    {
        $this->assertSame('dark', UserTheme::DARK->value);
        $this->assertSame('light', UserTheme::LIGHT->value);
    }

    #[Test]
    public function userLocaleValues(): void
    {
        $this->assertSame('fr', UserLocale::FR->value);
        $this->assertSame('en', UserLocale::EN->value);
    }

    #[Test]
    public function moderationStatusValues(): void
    {
        $this->assertSame('clean', ModerationStatus::CLEAN->value);
        $this->assertSame('flagged', ModerationStatus::FLAGGED->value);
        $this->assertSame('pending', ModerationStatus::PENDING->value);
    }

    #[Test]
    public function taskPriorityValues(): void
    {
        $this->assertSame('low', TaskPriority::LOW->value);
        $this->assertSame('medium', TaskPriority::MEDIUM->value);
        $this->assertSame('high', TaskPriority::HIGH->value);
        $this->assertSame('urgent', TaskPriority::URGENT->value);
    }
}
