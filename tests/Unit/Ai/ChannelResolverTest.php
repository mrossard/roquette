<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\ChannelResolver;
use App\Entity\Channel;
use App\Entity\Workspace;
use App\Repository\ChannelRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ChannelResolverTest extends TestCase
{
    private ChannelRepository $channelRepo;
    private WorkspaceRepository $workspaceRepo;
    private ChannelResolver $resolver;

    protected function setUp(): void
    {
        $this->channelRepo = $this->createMock(ChannelRepository::class);
        $this->workspaceRepo = $this->createMock(WorkspaceRepository::class);
        $this->resolver = new ChannelResolver($this->channelRepo, $this->workspaceRepo);
    }

    #[Test]
    public function resolveReturnsNullOnEmptyString(): void
    {
        $this->assertNull($this->resolver->resolve('   '));
    }

    #[Test]
    public function resolveFindsChannelInWorkspaceByName(): void
    {
        $channel = new Channel();
        $channel->setName('Général');
        $channel->setSlug('general');

        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getChannels')->willReturn(new ArrayCollection([$channel]));

        $this->workspaceRepo->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($workspace);

        $this->assertSame($channel, $this->resolver->resolve('général', 10));
    }

    #[Test]
    public function resolveFindsChannelInWorkspaceBySlug(): void
    {
        $channel = new Channel();
        $channel->setName('Dev Channel');
        $channel->setSlug('dev');

        $workspace = $this->createMock(Workspace::class);
        $workspace->method('getChannels')->willReturn(new ArrayCollection([$channel]));

        $this->workspaceRepo->expects($this->once())
            ->method('find')
            ->with(10)
            ->willReturn($workspace);

        $this->assertSame($channel, $this->resolver->resolve('dev', 10));
    }

    #[Test]
    public function resolveDelegatesToRepositoryFuzzyWhenNotInWorkspace(): void
    {
        $channel = new Channel();
        $channel->setName('Support Tech');
        $channel->setSlug('support-tech');

        $this->workspaceRepo->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn(null);

        $this->channelRepo->expects($this->once())
            ->method('findOneByNameOrSlugFuzzy')
            ->with('support')
            ->willReturn($channel);

        $this->assertSame($channel, $this->resolver->resolve('support', 5));
    }

    #[Test]
    public function resolveFromListMatchesExactFirstThenPartial(): void
    {
        $channel1 = new Channel();
        $channel1->setName('Projets');
        $channel1->setSlug('projets');

        $channel2 = new Channel();
        $channel2->setName('Projets Archives');
        $channel2->setSlug('projets-archives');

        $this->assertNull($this->resolver->resolveFromList('', [$channel1, $channel2]));

        // Exact slug/name match
        $this->assertSame($channel1, $this->resolver->resolveFromList('projets', [$channel1, $channel2]));

        // Partial match
        $this->assertSame($channel2, $this->resolver->resolveFromList('archives', [$channel1, $channel2]));
    }
}
