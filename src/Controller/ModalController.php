<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ChannelRepository;
use App\Repository\WebhookRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ModalController extends AbstractController
{
    public function __construct(
        private readonly \App\Service\Group\GroupProviderInterface $groupProvider,
    ) {}

    #[Route('/channels/{slug}/edit-modal', name: 'app_channel_edit_modal', methods: ['GET'])]
    public function editModal(
        string $slug,
        ChannelRepository $channelRepository,
        WebhookRepository $webhookRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$channel->isAdministrator($currentUser)) {
            return new Response('Accès refusé', 403);
        }

        $webhooks = $webhookRepository->findBy(['channel' => $channel], ['createdAt' => 'ASC']);

        $groups = $this->groupProvider->getGroups();
        $resolvedSubscriptions = [];
        foreach ($channel->getGroupSubscriptions() as $sub) {
            $grp = $this->groupProvider->getGroupByIdentifier($sub->getGroupIdentifier());
            $resolvedSubscriptions[] = [
                'id' => $sub->getId(),
                'identifier' => $sub->getGroupIdentifier(),
                'name' => $grp ? $grp->name : $sub->getGroupIdentifier(),
                'isGroupChannel' => $sub->isGroupChannel(),
            ];
        }

        return $this->render('modals/_edit_channel_modal.html.twig', [
            'activeChannel' => $channel,
            'webhooks' => $webhooks,
            'groups' => $groups,
            'resolvedSubscriptions' => $resolvedSubscriptions,
        ]);
    }

    #[Route('/channels/{slug}/invite-modal', name: 'app_channel_invite_modal', methods: ['GET'])]
    public function inviteModal(string $slug, ChannelRepository $channelRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        if ($channel->isDm() || !$channel->isPrivate() || $channel->getCreator() !== $currentUser) {
            return new Response('Accès refusé', 403);
        }

        return $this->render('modals/_invite_member_modal.html.twig', [
            'activeChannel' => $channel,
            'usersToInvite' => [], // Starting empty, search is done via AJAX
        ]);
    }

    #[Route('/channels/{slug}/members-modal', name: 'app_channel_members_modal', methods: ['GET'])]
    public function membersModal(
        string $slug,
        ChannelRepository $channelRepository,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
    ): Response {
        $channel = $channelRepository->findOneBy(['slug' => $slug]);

        if (!$channel) {
            return new Response('Canal non trouvé', 404);
        }

        $resolvedSubscriptions = [];
        $groupMembers = [];
        $resolvedUserIds = [];
        foreach ($channel->getMembers() as $m) {
            $resolvedUserIds[$m->getId()] = true;
        }

        foreach ($channel->getGroupSubscriptions() as $sub) {
            $grp = $this->groupProvider->getGroupByIdentifier($sub->getGroupIdentifier());
            $groupName = $grp ? $grp->name : $sub->getGroupIdentifier();

            $resolvedSubscriptions[] = [
                'identifier' => $sub->getGroupIdentifier(),
                'name' => $groupName,
                'isGroupChannel' => $sub->isGroupChannel(),
            ];

            // Resolve members
            $res = $this->resolveGroupMembers($sub->getGroupIdentifier(), $entityManager);
            foreach ($res['users'] as $u) {
                if (!array_key_exists($u->getId(), $resolvedUserIds)) {
                    $groupMembers[$u->getId()] = [
                        'user' => $u,
                        'viaGroup' => $groupName,
                        'isRegistered' => true,
                    ];
                }
            }
            foreach ($res['externalUsernames'] as $username) {
                $groupMembers['ext-' . $username] = [
                    'username' => $username,
                    'viaGroup' => $groupName,
                    'isRegistered' => false,
                ];
            }
        }

        $getName = static function (array $memberItem): string {
            if (!$memberItem['isRegistered']) {
                return $memberItem['username'];
            }
            $user = $memberItem['user'];
            $displayName = $user->getDisplayName();
            if ($displayName !== null && $displayName !== '') {
                return $displayName;
            }
            return $user->getUsername();
        };

        // Sort group members by username/name
        uasort($groupMembers, static fn($a, $b) => strcasecmp($getName($a), $getName($b)));

        return $this->render('modals/_channel_members_modal.html.twig', [
            'activeChannel' => $channel,
            'resolvedSubscriptions' => $resolvedSubscriptions,
            'groupMembers' => $groupMembers,
        ]);
    }

    /**
     * @return array{users: \App\Entity\User[], externalUsernames: string[]}
     */
    private function resolveGroupMembers(string $groupIdentifier, \Doctrine\ORM\EntityManagerInterface $entityManager): array
    {
        $localGroup = $entityManager->getRepository(\App\Entity\UserGroup::class)->findOneBy(['groupIdentifier' => $groupIdentifier]);
        if ($localGroup) {
            return [
                'users' => $localGroup->getMembers()->toArray(),
                'externalUsernames' => [],
            ];
        }

        $externalUsernames = $this->groupProvider->getGroupMembers($groupIdentifier);
        $users = [];
        if ($externalUsernames !== []) {
            $users = $entityManager->getRepository(\App\Entity\User::class)->findBy(['username' => $externalUsernames]);
        }

        $foundUsernames = array_map(static fn($u) => $u->getUsername(), $users);
        $unregistered = array_diff($externalUsernames, $foundUsernames);

        return [
            'users' => $users,
            'externalUsernames' => array_values($unregistered),
        ];
    }

    #[Route('/channels/create-modal', name: 'app_channel_create_modal', methods: ['GET'])]
    public function createModal(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $groups = $this->groupProvider->getGroups();

        return $this->render('modals/_create_channel_modal.html.twig', [
            'defaultTodo' => $request->query->getBoolean('defaultTodo', false),
            'groups' => $groups,
        ]);
    }
}
