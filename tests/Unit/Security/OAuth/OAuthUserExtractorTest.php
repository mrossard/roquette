<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\OAuth;

use App\Security\OAuth\OAuthUserExtractor;
use App\Service\RobotUserProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[AllowMockObjectsWithoutExpectations]
class OAuthUserExtractorTest extends TestCase
{
    private RobotUserProvider $robotUserProvider;
    private LoggerInterface $logger;
    private OAuthUserExtractor $extractor;

    protected function setUp(): void
    {
        $this->robotUserProvider = $this->createMock(RobotUserProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->extractor = new OAuthUserExtractor(
            $this->robotUserProvider,
            $this->logger,
            'username',
            'displayName',
        );
    }

    public function testExtractAttributesSuccess(): void
    {
        $userData = [
            'id' => 'oauth_456',
            'username' => 'alice',
            'displayName' => 'Alice Wonder',
            'mail' => 'alice@example.com',
        ];

        $this->robotUserProvider->expects(static::once())->method('isRobotUsername')->with('alice')->willReturn(false);

        $attrs = $this->extractor->extract($userData);

        static::assertSame('oauth_456', $attrs->oauthId);
        static::assertSame('alice', $attrs->username);
        static::assertSame('Alice Wonder', $attrs->displayName);
        static::assertSame('alice@example.com', $attrs->email);
    }

    public function testExtractAttributesFallsBackWhenDisplayNameNotPresent(): void
    {
        $userData = [
            'sub' => 'oauth_789',
            'username' => 'bob',
        ];

        $this->robotUserProvider->expects(static::once())->method('isRobotUsername')->with('bob')->willReturn(false);

        $attrs = $this->extractor->extract($userData);

        static::assertSame('oauth_789', $attrs->oauthId);
        static::assertSame('bob', $attrs->username);
        static::assertSame('bob', $attrs->displayName);
        static::assertNull($attrs->email);
    }

    public function testExtractAttributesThrowsWhenIncomplete(): void
    {
        $userData = [
            'displayName' => 'Unknown',
        ];

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Les informations utilisateur retournées par le serveur OAuth2 sont incomplètes.');

        $this->extractor->extract($userData);
    }

    public function testExtractAttributesThrowsWhenUsernameIsRobot(): void
    {
        $userData = [
            'id' => 'bot_1',
            'username' => 'roquette_bot',
        ];

        $this->robotUserProvider->expects(static::once())->method('isRobotUsername')->with('roquette_bot')->willReturn(true);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Connexion impossible avec un compte système.');

        $this->extractor->extract($userData);
    }
}
