<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\AppExtension;
use App\Service\MessageFormatter;
use PHPUnit\Framework\TestCase;

class AppExtensionTest extends TestCase
{
    private AppExtension $extension;

    protected function setUp(): void
    {
        $formatter = $this->createMock(MessageFormatter::class);
        $this->extension = new AppExtension($formatter);
    }

    public function testFormatReactionTooltipWithSingleUser(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice'], '😀');
        $this->assertSame('Alice a réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithMultipleUsers(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice', 'Bob'], '😀');
        $this->assertSame('Alice et Bob ont réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithThreeUsers(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice', 'Bob', 'Charlie'], '😀');
        $this->assertSame('Alice, Bob et Charlie ont réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithUnknownEmoji(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice'], '🚀');
        $this->assertSame('Alice a réagi avec :rocket:', $result);
    }
}
