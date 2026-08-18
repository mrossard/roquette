<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Entity\Channel;
use App\Entity\User;
use App\Security\Voter\ChannelAccessVoter;
use App\Service\ChannelAccessService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

#[AllowMockObjectsWithoutExpectations]
class ChannelAccessVoterTest extends TestCase
{
    private ChannelAccessService $channelAccessService;
    private ChannelAccessVoter $voter;

    protected function setUp(): void
    {
        $this->channelAccessService = $this->createMock(ChannelAccessService::class);
        $this->voter = new ChannelAccessVoter($this->channelAccessService);
    }

    public function testVoteOnAttributeGrantedWhenServiceAllows(): void
    {
        $user = new User();
        $channel = new Channel();

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->channelAccessService
            ->expects(static::once())
            ->method('canUserAccess')
            ->with($channel, $user)
            ->willReturn(true);

        $result = $this->voter->vote($token, $channel, [ChannelAccessVoter::VIEW]);
        static::assertSame(ChannelAccessVoter::ACCESS_GRANTED, $result);
    }

    public function testVoteOnAttributeDeniedWhenServiceDenies(): void
    {
        $user = new User();
        $channel = new Channel();

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $this->channelAccessService
            ->expects(static::once())
            ->method('canUserAccess')
            ->with($channel, $user)
            ->willReturn(false);

        $result = $this->voter->vote($token, $channel, [ChannelAccessVoter::VIEW]);
        static::assertSame(ChannelAccessVoter::ACCESS_DENIED, $result);
    }

    public function testAbstainOnUnsupportedSubjectOrAttribute(): void
    {
        $token = $this->createMock(TokenInterface::class);

        static::assertSame(ChannelAccessVoter::ACCESS_ABSTAIN, $this->voter->vote(
            $token,
            new \stdClass(),
            [ChannelAccessVoter::VIEW],
        ));

        static::assertSame(ChannelAccessVoter::ACCESS_ABSTAIN, $this->voter->vote(
            $token,
            new Channel(),
            ['UNSUPPORTED_ATTRIBUTE'],
        ));
    }
}
