<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Message;
use App\Repository\ChannelRepository;
use App\Repository\UserChannelReadRepository;
use App\Service\MessageFormatter;
use App\Twig\AppExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class AppExtensionTest extends TestCase
{
    private AppExtension $extension;
    private ChannelRepository $channelRepository;

    protected function setUp(): void
    {
        $formatter = $this->createMock(MessageFormatter::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $this->channelRepository = $this->createMock(ChannelRepository::class);
        $ucrRepository = $this->createMock(UserChannelReadRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $parameters = []) {
                if ($id === 'et') {
                    return 'et';
                }

                return strtr($id, $parameters);
            });

        $this->extension = new AppExtension($formatter, $translator, $this->channelRepository, $ucrRepository, $entityManager, 'roquette');
    }

    #[Test]
    public function getSubchannelDoesNotCrashWithoutManagedMessages(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getId')->willReturn(1);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getIdentityMap')->willReturn([Message::class => [$message]]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($uow);

        $query = $this->createMock(\Doctrine\ORM\Query::class);
        $query->method('getResult')->willReturn([]);
        $queryBuilder = $this->createMock(\Doctrine\ORM\QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);
        $this->channelRepository->method('createQueryBuilder')->willReturn($queryBuilder);

        $extension = new AppExtension(
            $this->createMock(MessageFormatter::class),
            $this->createMock(TranslatorInterface::class),
            $this->channelRepository,
            $this->createMock(UserChannelReadRepository::class),
            $entityManager,
            'roquette',
        );

        static::assertNull($extension->getSubchannel($message));
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
