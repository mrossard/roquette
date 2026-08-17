<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\PendingConfirmationService;
use App\Entity\Channel;
use App\Entity\Message;
use App\Entity\User;
use App\Message\LlmQueryMessage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class RobotInteractionService
{
    public function __construct(
        private readonly RobotUserProvider $robotUserProvider,
        private readonly LlmRateLimiter $llmRateLimiter,
        private readonly MessageBusInterface $messageBus,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly PendingConfirmationService $pendingConfirmationService,
    ) {}

    public function isRobotDm(Channel $channel, User $currentUser): bool
    {
        return $channel->getSlug() === $this->robotUserProvider->getDmChannelSlug($currentUser);
    }

    public function isRobotMentioned(string $messageText): bool
    {
        $robot = $this->robotUserProvider->getRobotUser();
        if ($robot === null) {
            return false;
        }

        $rawUsername = $robot->getUsername();
        $name = ($rawUsername !== null && $rawUsername !== '') ? $rawUsername : User::ROBOT_USERNAME;
        $tokenAlias = strtok($name, '-');
        $alias = ($tokenAlias !== false && $tokenAlias !== '') ? $tokenAlias : $name;

        return preg_match(
            '/@(?:' . preg_quote($name, '/') . '|' . preg_quote($alias, '/') . ')(?![\p{L}\p{N}-])/iu',
            $messageText,
        ) === 1;
    }

    public function checkRobotDmLlmRateLimit(
        bool $isDmWithRobot,
        bool $isPoll,
        bool $hasFile,
        User $currentUser,
        Channel $channel,
    ): ?PublishResult {
        if ($isDmWithRobot && !$isPoll && !$hasFile && !$this->llmRateLimiter->consume($currentUser)) {
            return PublishResult::error(
                error: $this->translator->trans(LlmRateLimiter::MESSAGE_KEY),
                channel: $channel,
                statusCode: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        return null;
    }

    public function tryHandleConfirmation(User $currentUser, Channel $channel, string $messageText): ?PublishResult
    {
        $pendingToken = $this->pendingConfirmationService->getPendingConfirmation($currentUser, $channel->getSlug());
        if ($pendingToken !== null && $this->pendingConfirmationService->isConfirmation($messageText, $pendingToken, $currentUser)) {
            if ($this->pendingConfirmationService->executeConfirmation($pendingToken, $currentUser)) {
                return PublishResult::ok($channel, null, '');
            }
        }

        return null;
    }

    public function handleRobotMentionInChannel(
        Channel $channel,
        User $currentUser,
        string $messageText,
        ?int $workspaceId = null,
    ): PublishResult {
        if (!$this->llmRateLimiter->consume($currentUser)) {
            return PublishResult::error(
                error: $this->translator->trans(LlmRateLimiter::MESSAGE_KEY),
                channel: $channel,
                statusCode: Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        $helpMessageId = 'help-' . uniqid();
        $this->messageBus->dispatch(
            new LlmQueryMessage($messageText, $currentUser->getId(), $channel->getSlug(), $helpMessageId, workspaceId: $workspaceId),
        );

        $tempMessage = new Message();
        $tempMessage->setAuthor($currentUser);
        $tempMessage->setChannel($channel);

        $oobHtml = $this->twig->render('dashboard/_help_message_oob.html.twig', [
            'answer' => null,
            'question' => $messageText,
            'helpMessageId' => $helpMessageId,
            'activeChannel' => $channel,
            'timestamp' => new \DateTime(),
        ]);

        return PublishResult::ok($channel, $tempMessage, $oobHtml);
    }

    public function dispatchRobotDmQuery(Message $message, string $messageText, ?int $workspaceId = null): void
    {
        $this->messageBus->dispatch(
            new LlmQueryMessage(
                $messageText,
                $message->getAuthor()->getId(),
                $message->getChannel()->getSlug(),
                'help-' . uniqid(),
                workspaceId: $workspaceId,
            ),
        );
    }
}
