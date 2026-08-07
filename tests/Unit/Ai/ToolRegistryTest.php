<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\ToolCall;

class ToolRegistryTest extends TestCase
{
    public function testGetOpenAiToolsReturnsFunctionDefinitions(): void
    {
        $registry = new ToolRegistry([new FakeTool()]);

        $tools = $registry->getOpenAiTools();

        static::assertCount(1, $tools);
        static::assertSame('function', $tools[0]['type']);
        static::assertSame('fake_tool', $tools[0]['function']['name']);
        static::assertSame('object', $tools[0]['function']['parameters']['type']);
        static::assertSame(['channelSlug'], $tools[0]['function']['parameters']['required']);
    }

    public function testExecuteInvokesToolAndInjectsAuthorUserId(): void
    {
        $tool = new FakeTool();
        $registry = new ToolRegistry([$tool]);

        $result = $registry->execute(
            new ToolCall('1', 'fake_tool', ['channelSlug' => 'general']),
            42,
        );

        static::assertSame('Tool executed for general', $result);
        static::assertSame('general', $tool->lastChannelSlug);
        static::assertSame(42, $tool->lastAuthorUserId);
    }

    public function testExecuteInjectsWorkspaceId(): void
    {
        $tool = new FakeTool();
        $registry = new ToolRegistry([$tool]);

        $result = $registry->execute(
            new ToolCall('1', 'fake_tool', ['channelSlug' => 'general']),
            null,
            7,
        );

        static::assertSame('Tool executed for general', $result);
        static::assertSame(7, $tool->lastWorkspaceId);
    }

    public function testExecuteLeavesWorkspaceIdNullWhenNotProvided(): void
    {
        $tool = new FakeTool();
        $registry = new ToolRegistry([$tool]);

        $registry->execute(
            new ToolCall('1', 'fake_tool', ['channelSlug' => 'general']),
            null,
        );

        static::assertNull($tool->lastWorkspaceId);
    }

    public function testExecuteIgnoresUnknownArguments(): void
    {
        $tool = new FakeTool();
        $registry = new ToolRegistry([$tool]);

        $result = $registry->execute(
            new ToolCall('1', 'fake_tool', ['channelSlug' => 'general', 'injected' => 'x']),
            null,
        );

        static::assertSame('Tool executed for general', $result);
        static::assertNull($tool->lastAuthorUserId);
    }

    public function testExecuteUnknownToolReturnsError(): void
    {
        $registry = new ToolRegistry([new FakeTool()]);

        $result = $registry->execute(new ToolCall('1', 'missing_tool', []));

        static::assertStringContainsString('Outil inconnu', $result);
    }

    public function testGetReturnsToolByNameOrNull(): void
    {
        $registry = new ToolRegistry([new FakeTool()]);

        static::assertInstanceOf(FakeTool::class, $registry->get('fake_tool'));
        static::assertNull($registry->get('missing_tool'));
    }
}
