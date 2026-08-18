<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class EmailVerificationServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $userRepository;
    private MailerInterface $mailer;
    private UrlGeneratorInterface $urlGenerator;
    private Environment $twig;
    private TranslatorInterface $translator;
    private LoggerInterface $logger;
    private EmailVerificationService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->translator->method('trans')->willReturnCallback(static fn(string $id) => $id);

        $this->service = new EmailVerificationService(
            $this->entityManager,
            $this->userRepository,
            $this->mailer,
            $this->urlGenerator,
            $this->twig,
            $this->translator,
            $this->logger,
        );
    }

    public function testSendVerificationEmailReturnsFalseWhenUserHasNoEmail(): void
    {
        $user = new User();
        $user->setUsername('noemailuser');
        $user->setEmail(null);

        static::assertFalse($this->service->sendVerificationEmail($user));
    }

    public function testSendVerificationEmailSuccess(): void
    {
        $user = new User();
        $user->setUsername('alice');
        $user->setEmail('alice@example.com');

        $this->entityManager->expects(static::once())->method('flush');

        $this->urlGenerator
            ->expects(static::once())
            ->method('generate')
            ->with(
                'app_verify_email',
                static::callback(static fn(array $params) => is_string($params['token']) && strlen($params['token']) === 64),
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://roquette.test/verify-email/abcdef1234567890');

        $this->twig
            ->expects(static::exactly(2))
            ->method('render')
            ->willReturnMap([
                ['emails/verify_email.html.twig', [
                    'user' => $user,
                    'verificationUrl' => 'https://roquette.test/verify-email/abcdef1234567890',
                ], '<p>Vérifiez votre email</p>'],
                ['emails/verify_email.txt.twig', [
                    'user' => $user,
                    'verificationUrl' => 'https://roquette.test/verify-email/abcdef1234567890',
                ], 'Vérifiez votre email'],
            ]);

        $this->mailer
            ->expects(static::once())
            ->method('send')
            ->with(static::callback(static fn(Email $email) => $email->getTo()[0]->getAddress() === 'alice@example.com'
                && str_contains((string) $email->getHtmlBody(), 'Vérifiez votre email')));

        $this->logger->expects(static::once())->method('info');

        $result = $this->service->sendVerificationEmail($user);

        static::assertTrue($result);
        static::assertNotNull($user->getEmailVerificationToken());
        static::assertSame(64, strlen($user->getEmailVerificationToken()));
    }

    public function testSendVerificationEmailCatchesMailerExceptionAndReturnsFalse(): void
    {
        $user = new User();
        $user->setUsername('bob');
        $user->setEmail('bob@example.com');

        $this->urlGenerator
            ->method('generate')
            ->willReturn('https://roquette.test/verify-email/testtoken');

        $this->mailer
            ->expects(static::once())
            ->method('send')
            ->willThrowException(new \RuntimeException('SMTP Connection failed'));

        $this->logger->expects(static::once())->method('error');

        $result = $this->service->sendVerificationEmail($user);

        static::assertFalse($result);
    }

    public function testVerifyEmailTokenReturnsNullWhenNotFound(): void
    {
        $this->userRepository
            ->expects(static::once())
            ->method('findOneBy')
            ->with(['emailVerificationToken' => 'invalidtoken'])
            ->willReturn(null);

        static::assertNull($this->service->verifyEmailToken('invalidtoken'));
    }

    public function testVerifyEmailTokenSuccess(): void
    {
        $user = new User();
        $user->setUsername('charlie');
        $user->setEmail('charlie@example.com');
        $user->setEmailVerificationToken('validtoken123');

        $this->userRepository
            ->expects(static::once())
            ->method('findOneBy')
            ->with(['emailVerificationToken' => 'validtoken123'])
            ->willReturn($user);

        $this->entityManager->expects(static::once())->method('flush');
        $this->logger->expects(static::once())->method('info');

        $verifiedUser = $this->service->verifyEmailToken('validtoken123');

        static::assertSame($user, $verifiedUser);
        static::assertNull($user->getEmailVerificationToken());
        static::assertTrue($user->isEmailVerified());
        static::assertNotNull($user->getEmailVerifiedAt());
    }
}
