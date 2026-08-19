<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reminder;
use App\Entity\User;
use App\Message\SendReminderMessage;
use App\Repository\MessageRepository;
use App\Repository\ReminderRepository;
use App\Service\ChannelAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class MessageReminderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReminderRepository $reminderRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ChannelAccessService $channelAccessService,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/messages/{id}/remind', name: 'app_message_remind', methods: ['POST'])]
    public function schedule(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $message = $this->messageRepository->find($id);

        if ($message === null) {
            return new Response($this->translator->trans('Message introuvable.'), 404);
        }

        $channel = $message->getChannel();
        if ($channel === null || !$this->channelAccessService->canUserAccess($channel, $currentUser)) {
            return new Response($this->translator->trans('Accès refusé.'), 403);
        }

        $preset = $request->request->getString('preset', '1h');
        $customDatetime = $request->request->getString('custom_datetime', '');

        $scheduledAt = $this->calculateScheduledAt($preset, $customDatetime);
        if ($scheduledAt === null) {
            return new Response($this->translator->trans('Date de rappel invalide ou passée.'), 400);
        }

        $now = new \DateTimeImmutable();
        $delaySeconds = max(60, $scheduledAt->getTimestamp() - $now->getTimestamp());
        $delayMs = $delaySeconds * 1000;

        // Check if a pending reminder already exists for this message & user
        $reminder = $this->reminderRepository->findPendingForMessageAndUser($message, $currentUser);
        if ($reminder === null) {
            $reminder = new Reminder();
            $reminder->setUser($currentUser);
            $reminder->setChannel($channel);
            $reminder->setTargetMessage($message);
            $reminder->setMessage(mb_substr($message->getContent() ?? '', 0, 180));
        }

        $reminder->setScheduledAt($scheduledAt);
        $reminder->setStatus('pending');

        $this->em->persist($reminder);
        $this->em->flush();

        $this->bus->dispatch(new SendReminderMessage($reminder->getId()), [new DelayStamp($delayMs)]);

        if ($request->headers->get('HX-Request')) {
            $response = $this->render('dashboard/_reminder_action.html.twig', [
                'message_id' => $message->getId(),
                'activeReminder' => $reminder,
            ]);

            $toastText = sprintf(
                '⏰ %s (%s)',
                $this->translator->trans('Rappel programmé'),
                $scheduledAt->format('d/m H:i'),
            );
            $response->headers->set(
                'HX-Trigger',
                (string) json_encode([
                    'showToast' => [
                        'message' => $toastText,
                        'type' => 'success',
                    ],
                ]),
            );

            return $response;
        }

        $this->addFlash('success', $this->translator->trans('Rappel programmé avec succès.'));

        return $this->redirectToRoute('app_channel', ['slug' => $channel->getSlug()]);
    }

    #[Route('/reminders/{id}/cancel', name: 'app_reminder_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $reminder = $this->reminderRepository->find($id);

        if ($reminder === null || $reminder->getUser()?->getId() !== $currentUser->getId()) {
            return new Response($this->translator->trans('Rappel introuvable.'), 404);
        }

        $messageId = $reminder->getTargetMessage()?->getId();
        $reminder->setStatus('cancelled');
        $this->em->flush();

        if ($request->headers->get('HX-Request')) {
            $response = $this->render('dashboard/_reminder_action.html.twig', [
                'message_id' => $messageId,
                'activeReminder' => null,
            ]);

            $response->headers->set(
                'HX-Trigger',
                (string) json_encode([
                    'showToast' => [
                        'message' => '⏰ ' . $this->translator->trans('Rappel annulé.'),
                        'type' => 'info',
                    ],
                ]),
            );

            return $response;
        }

        $this->addFlash('success', $this->translator->trans('Rappel annulé.'));

        return new Response('');
    }

    #[Route('/reminders', name: 'app_reminders_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $reminders = $this->reminderRepository->findPendingByUser($currentUser);

        if ($request->headers->get('HX-Request')) {
            return $this->render('modals/reminders.html.twig', [
                'reminders' => $reminders,
            ]);
        }

        return $this->render('modals/reminders.html.twig', [
            'reminders' => $reminders,
        ]);
    }

    private function calculateScheduledAt(string $preset, string $customDatetime): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        return match ($preset) {
            '20m' => $now->modify('+20 minutes'),
            '1h' => $now->modify('+1 hour'),
            '3h' => $now->modify('+3 hours'),
            'tomorrow_9am' => new \DateTimeImmutable('tomorrow 09:00:00'),
            'next_monday_9am' => new \DateTimeImmutable('next monday 09:00:00'),
            'custom' => $this->parseCustomDatetime($customDatetime, $now),
            default => $now->modify('+1 hour'),
        };
    }

    private function parseCustomDatetime(string $customDatetime, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($customDatetime === '') {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($customDatetime);
            if ($parsed <= $now) {
                return null;
            }

            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }
}
