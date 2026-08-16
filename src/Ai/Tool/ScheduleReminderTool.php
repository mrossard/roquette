<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\ChannelResolver;
use App\Entity\Reminder;
use App\Entity\User;
use App\Message\SendReminderMessage;
use App\Repository\UserRepository;
use App\Service\ChannelAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class ScheduleReminderTool implements AiToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private MessageBusInterface $bus,
        private ChannelResolver $channelResolver,
        private ChannelAccessService $channelAccessService,
    ) {}

    public function getName(): string
    {
        return 'schedule_reminder';
    }

    public function getDescription(): string
    {
        return 'Programme un rappel contextuel pour un utilisateur dans un canal spécifique à une heure future (délai en minutes).';
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'channelSlug' => ['type' => 'string', 'description' => "Le slug du canal où publier le rappel (ex: 'assistant' pour un rappel personnel)."],
                'reminderText' => ['type' => 'string', 'description' => "Le texte exact du rappel : l'action ou le sujet brut, sans les mots 'rappel' ni le délai."],
                'delayMinutes' => ['type' => 'integer', 'description' => 'Le délai en minutes avant de poster le rappel (minimum 1).'],
            ],
            'required' => ['channelSlug', 'reminderText', 'delayMinutes'],
        ];
    }

    /**
     * @param string $channelSlug Le slug du canal où publier le rappel (ex: "general").
     * @param string $reminderText Le texte exact du rappel à envoyer.
     * @param int $delayMinutes Le délai en minutes avant de poster le rappel (minimum 1 min).
     * @param int|null $authorUserId ID de l'utilisateur qui demande le rappel.
     * @param int|null $workspaceId ID du workspace courant (optionnel).
     */
    public function __invoke(
        string $channelSlug,
        string $reminderText,
        int $delayMinutes,
        ?int $authorUserId = null,
        ?int $workspaceId = null,
    ): string {
        $user = null;
        if ($authorUserId !== null) {
            $user = $this->userRepository->find($authorUserId);
        }

        // Si aucun canal valide n'est fourni ou s'il s'agit d'un rappel personnel, viser en priorité le DM avec l'assistant (User::ROBOT_USERNAME)
        $channel = null;
        if ($user) {
            $dmSlug = 'dm-' . User::ROBOT_USERNAME . '-' . $user->getSlug();
            if ($channelSlug === 'assistant' || $channelSlug === 'dm' || str_contains($channelSlug, 'robot')) {
                $channel = $this->channelResolver->resolve($dmSlug, $workspaceId);
            }
        }

        if (!$channel) {
            $channel = $this->channelResolver->resolve($channelSlug, $workspaceId);
        }

        if (!$channel && $user) {
            // Fallback 1: Canal DM avec le robot roquette
            $dmSlug = 'dm-' . User::ROBOT_USERNAME . '-' . $user->getSlug();
            $channel = $this->channelResolver->resolve($dmSlug, $workspaceId);
        }

        if (!$channel) {
            return sprintf("Impossible de programmer le rappel : le canal '%s' n'existe pas.", $channelSlug);
        }

        $user = null;
        if ($authorUserId !== null) {
            $user = $this->userRepository->find($authorUserId);
        }
        if (!$user) {
            $user = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME])
                ?? $this->userRepository->findOneBy([]);
        }

        if ($user !== null && !$this->channelAccessService->canUserAccess($channel, $user)) {
            return sprintf("Impossible de programmer le rappel : vous n'avez pas accès au canal '%s'.", $channel->getName());
        }

        if ($delayMinutes < 1) {
            $delayMinutes = 1;
        }

        $scheduledAt = new \DateTimeImmutable(sprintf("+%d minutes", $delayMinutes));

        $reminder = new Reminder();
        $reminder->setUser($user);
        $reminder->setChannel($channel);
        $reminder->setMessage(trim($reminderText));
        $reminder->setScheduledAt($scheduledAt);
        $reminder->setStatus('pending');

        $this->em->persist($reminder);
        $this->em->flush();

        // Convertir le délai de minutes en milli-secondes pour DelayStamp de Symfony Messenger
        $delayMs = $delayMinutes * 60 * 1000;
        $this->bus->dispatch(
            new SendReminderMessage($reminder->getId()),
            [new DelayStamp($delayMs)]
        );

        return sprintf(
            "C'est noté ! J'ai programmé votre rappel pour le canal #%s à %s (dans %d minutes).",
            $channel->getName(),
            $scheduledAt->format('H:i'),
            $delayMinutes
        );
    }
}
