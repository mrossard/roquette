<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\AppExtension;
use App\Service\MessageFormatter;
use App\Repository\ChannelRepository;
use PHPUnit\Framework\TestCase;

use Symfony\Contracts\Translation\TranslatorInterface;

class AppExtensionTest extends TestCase
{
    private AppExtension $extension;

    protected function setUp(): void
    {
        $formatter = $this->createMock(MessageFormatter::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $channelRepository = $this->createMock(ChannelRepository::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $parameters = []) {
            if ($id === 'et') {
                return 'et';
            }

            return strtr($id, $parameters);
        });

        $this->extension = new AppExtension($formatter, $translator, $channelRepository);
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
