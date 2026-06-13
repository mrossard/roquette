<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;


use App\Repository\ChannelRepository;
use App\Service\MessageFormatter;
use App\Twig\AppExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class AppExtensionTest extends TestCase
{
    private AppExtension $extension;

    protected function setUp(): void
    {
        $formatter = $this->createMock(MessageFormatter::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $channelRepository = $this->createMock(ChannelRepository::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $parameters = []) {
                if ($id === 'et') {
                    return 'et';
                }

                return strtr($id, $parameters);
            });

        $this->extension = new AppExtension($formatter, $translator, $channelRepository, 'roquette');
    }

    public function testFormatReactionTooltipWithSingleUser(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice'], '😀');
        static::assertSame('Alice a réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithMultipleUsers(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice', 'Bob'], '😀');
        static::assertSame('Alice et Bob ont réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithThreeUsers(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice', 'Bob', 'Charlie'], '😀');
        static::assertSame('Alice, Bob et Charlie ont réagi avec :grinning:', $result);
    }

    public function testFormatReactionTooltipWithUnknownEmoji(): void
    {
        $result = $this->extension->formatReactionTooltip(['Alice'], '🚀');
        static::assertSame('Alice a réagi avec :rocket:', $result);
    }
}
