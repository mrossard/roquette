<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Entity\Reminder;
use App\Message\SendReminderMessage;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Tool\Attribute\AsTool;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsTool(
    name: 'schedule_reminder',
    description: 'Programme un rappel contextuel pour un utilisateur dans un canal spécifique à une heure future (délai en minutes).'
)]
final readonly class ScheduleReminderTool
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChannelRepository $channelRepository,
        private UserRepository $userRepository,
        private MessageBusInterface $bus,
    ) {}

    /**
     * @param string $channelSlug Le slug du canal où publier le rappel (ex: "general").
     * @param string $reminderText Le texte exact du rappel à envoyer.
     * @param int $delayMinutes Le délai en minutes avant de poster le rappel (minimum 1 min).
     * @param int|null $authorUserId ID de l'utilisateur qui demande le rappel.
     */
    public function __invoke(
        string $channelSlug,
        string $reminderText,
        int $delayMinutes,
        ?int $authorUserId = null,
    ): string {
        $user = null;
        if ($authorUserId !== null) {
            $user = $this->userRepository->find($authorUserId);
        }

        // Si aucun canal valide n'est fourni ou s'il s'agit d'un rappel personnel, viser en priorité le DM avec l'assistant ("robot-roquette")
        $channel = null;
        if ($user) {
            $dmSlug = 'dm-robot-roquette-' . $user->getSlug();
            if ($channelSlug === 'assistant' || $channelSlug === 'dm' || str_contains($channelSlug, 'robot')) {
                $channel = $this->channelRepository->findOneBy(['slug' => $dmSlug]);
            }
        }

        if (!$channel) {
            $channel = $this->channelRepository->findOneBy(['slug' => strtolower($channelSlug)]);
        }

        if (!$channel && $user) {
            // Fallback 1: Canal DM avec le robot roquette
            $dmSlug = 'dm-robot-roquette-' . $user->getSlug();
            $channel = $this->channelRepository->findOneBy(['slug' => $dmSlug]);
        }

        if (!$channel) {
            $channels = $this->channelRepository->findAll();
            foreach ($channels as $c) {
                if (
                    strtolower($c->getSlug()) === strtolower($channelSlug)
                    || strtolower($c->getName()) === strtolower($channelSlug)
                    || str_contains(strtolower($c->getName()), strtolower($channelSlug))
                ) {
                    $channel = $c;
                    break;
                }
            }
        }

        if (!$channel) {
            return sprintf("Impossible de programmer le rappel : le canal '%s' n'existe pas.", $channelSlug);
        }

        $user = null;
        if ($authorUserId !== null) {
            $user = $this->userRepository->find($authorUserId);
        }
        if (!$user) {
            $user = $this->userRepository->findOneBy(['username' => 'robot-roquette'])
                ?? $this->userRepository->findOneBy([]);
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
