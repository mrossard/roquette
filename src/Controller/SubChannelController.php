<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Enum\AuditAction;
use App\Repository\ChannelRepository;
use App\Repository\MessageRepository;
use App\Service\AuditLoggerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class SubChannelController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly AuditLoggerService $auditLogger,
    ) {}

    #[Route('/messages/{id}/sub-channel', name: 'app_message_create_subchannel', methods: ['POST'])]
    public function createSubChannel(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        return $this->doCreateSubChannel($id, $request, $messageRepository, $entityManager, false);
    }

    #[Route('/messages/{id}/sub-channel-todo', name: 'app_message_create_subchannel_todo', methods: ['POST'])]
    public function createSubChannelTodo(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        return $this->doCreateSubChannel($id, $request, $messageRepository, $entityManager, true);
    }

    private function doCreateSubChannel(
        int $id,
        Request $request,
        MessageRepository $messageRepository,
        EntityManagerInterface $entityManager,
        bool $isTodoList,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $parentMessage = $messageRepository->find($id);
        if (!$parentMessage) {
            return new Response($this->translator->trans('Message non trouvé.'), 404);
        }

        $existingSubChannel = $entityManager
            ->getRepository(Channel::class)
            ->findOneBy(['parentMessage' => $parentMessage]);
        if ($existingSubChannel) {
            $url = $this->generateUrl('app_channel', ['slug' => $existingSubChannel->getSlug()]);
            if ($request->headers->has('HX-Request')) {
                return new Response(null, 204, ['HX-Redirect' => $url]);
            }

            return $this->redirect($url);
        }

        $parentChannel = $parentMessage->getChannel();
        if ($parentChannel->isSubChannel() && !$parentChannel->isTodoList()) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $channelRepository = $entityManager->getRepository(Channel::class);
        if (!$channelRepository->canUserAccess($parentChannel, $currentUser)) {
            return new Response($this->translator->trans('Non autorisé.'), 403);
        }

        $content = $parentMessage->getContent() ?? $parentMessage->getFileName() ?? 'Discussion';
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 40);

        $slug = 'sc-'
            . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($name))
            . '-'
            . substr(bin2hex(random_bytes(3)), 0, 6);
        $slug = trim($slug, '-');

        if ($entityManager->getRepository(Channel::class)->findOneBy(['slug' => $slug])) {
            $slug .= '-' . rand(100, 999);
        }

        $channel = new Channel();
        $channel->setName($name);
        $channel->setSlug($slug);
        $channel->setDescription($this->translator->trans('Discussion créée depuis un message.'));
        $channel->setParentMessage($parentMessage);
        $channel->setCreator($currentUser);
        $channel->setIsPrivate($parentChannel->isPrivate());
        $channel->setMessageRetentionMonths($parentChannel->getMessageRetentionMonths());
        if ($isTodoList) {
            $channel->setIsTodoList(true);
        }
        else {
            // only auto-add members on non "todolist" subchannels
            foreach ($parentChannel->getMembers() as $member) {
                $channel->addMember($member);
            }
        }

        $entityManager->persist($channel);
        $entityManager->flush();

        $this->auditLogger->log(AuditAction::CHANNEL_CREATE, $currentUser, [
            'channel_id' => $channel->getId(),
            'channel_name' => $channel->getName(),
            'slug' => $channel->getSlug(),
            'is_private' => $channel->isPrivate(),
            'parent_channel_id' => $parentChannel->getId(),
            'parent_message_id' => $parentMessage->getId(),
        ]);

        $this->logger->info(sprintf(
            'Sub-channel created: "%s" (slug: "%s", todo: %s) from message #%d by user "%s"',
            $channel->getName(),
            $channel->getSlug(),
            $isTodoList ? 'yes' : 'no',
            $parentMessage->getId(),
            $currentUser->getUsername(),
        ));

        $this->addFlash('success', $this->translator->trans('Discussion "%channelName%" créée.', [
            '%channelName%' => $channel->getName(),
        ]));

        $url = $this->generateUrl('app_channel', ['slug' => $channel->getSlug()]);
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }
}
