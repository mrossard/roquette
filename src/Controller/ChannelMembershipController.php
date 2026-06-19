<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ChannelAccessTrait;
use App\Controller\Trait\MessageRendererTrait;
use App\Entity\Channel;
use App\Entity\UserChannelRead;
use App\Entity\User;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
final class ChannelMembershipController extends AbstractController
{
    use MessageRendererTrait;
    use ChannelAccessTrait;

    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    #[Route('/dm/{username}', name: 'app_dm_open')]
    public function openDm(
        string $username,
        EntityManagerInterface $entityManager,
        ChannelRepository $channelRepository,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $partner = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$partner) {
            $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));

            return $this->redirectToRoute('app_dashboard');
        }

        if ($partner->getId() === $currentUser->getId()) {
            $this->addFlash(
                'error',
                $this->translator->trans('Vous ne pouvez pas envoyer de message direct à vous-même.'),
            );

            return $this->redirectToRoute('app_dashboard');
        }

        $dmChannel = $channelRepository->findDmBetween($currentUser, $partner);

        if (!$dmChannel) {
            $dmChannel = new Channel();
            $dmChannel->setIsPrivate(true);
            $dmChannel->setIsDm(true);

            $minId = min($currentUser->getId(), $partner->getId());
            $maxId = max($currentUser->getId(), $partner->getId());
            $slug = sprintf('dm-%d-%d', $minId, $maxId);

            $dmChannel->setSlug($slug);
            $dmChannel->setName(sprintf('%s & %s', $currentUser->getUsername(), $partner->getUsername()));
            $dmChannel->setDescription(sprintf(
                'Conversation privée entre %s et %s',
                $currentUser->getUsername(),
                $partner->getUsername(),
            ));

            $dmChannel->setCreator($currentUser);
            $dmChannel->addMember($currentUser);
            $dmChannel->addMember($partner);

            $entityManager->persist($dmChannel);
            $entityManager->flush();
        } else {
            if (!$dmChannel->getMembers()->contains($currentUser)) {
                $dmChannel->addMember($currentUser);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('app_channel', ['slug' => $dmChannel->getSlug()]);
    }

    #[Route('/channels/{slug}/join', name: 'app_channel_join', methods: ['POST'])]
    public function joinChannel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if ($channel->isPrivate()) {
            return new Response(
                $this->translator->trans('Vous ne pouvez pas rejoindre directement un canal privé.'),
                403,
            );
        }

        if (!$channel->getMembers()->contains($currentUser)) {
            $channel->addMember($currentUser);
            $entityManager->flush();
        }

        $url = $this->generateUrl('app_channel', ['slug' => $slug]);
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    #[Route('/channels/{slug}/leave', name: 'app_channel_leave', methods: ['POST'])]
    public function leaveChannel(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response($this->translator->trans('Canal non trouvé.'), 404);
        }

        if ($channel->getMembers()->contains($currentUser)) {
            $channel->removeMember($currentUser);

            $ucrRepo = $entityManager->getRepository(UserChannelRead::class);
            $ucr = $ucrRepo->findOneBy(['user' => $currentUser, 'channel' => $channel]);
            if ($ucr) {
                $entityManager->remove($ucr);
            }

            $entityManager->flush();
        }

        $url = $this->generateUrl('app_dashboard');
        if ($request->headers->has('HX-Request')) {
            return new Response(null, 204, ['HX-Redirect' => $url]);
        }

        return $this->redirect($url);
    }

    #[Route('/channels/{slug}/admin-chip-add', name: 'app_channel_admin_chip_add', methods: ['POST'])]
    public function addAdminChip(
        string $slug,
        Request $request,
        UserRepository $userRepository,
        ChannelRepository $channelRepository,
    ): Response {
        $userId = $request->request->get('userId');
        $user = $userRepository->find((int) $userId);
        if (!$user) {
            return new Response('', 400);
        }

        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('', 404);
        }

        if (!$channel->getMembers()->contains($user)) {
            return new Response(
                '<div class="error-message">'
                . htmlspecialchars($this->translator->trans("Cet utilisateur n'est pas membre de ce canal."), ENT_QUOTES, 'UTF-8')
                . '</div>',
                400,
            );
        }

        return $this->render('dashboard/_admin_chip.html.twig', [
            'member' => $user,
        ]);
    }

    #[Route('/channels/{slug}/admin-autocomplete', name: 'app_channel_admin_autocomplete', methods: ['GET'])]
    public function adminAutocomplete(string $slug, Request $request, ChannelRepository $channelRepository): Response
    {
        $channel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            return new Response('', 404);
        }

        $query = trim($request->query->get('search', ''));
        if ($query === '') {
            return new Response(
                '<div id="admin-autocomplete-suggestions" class="emoji-autocomplete-dropdown" style="display: none;"></div>',
            );
        }

        $matches = [];
        foreach ($channel->getMembers() as $member) {
            if ($channel->getAdministrators()->contains($member) || $member === $channel->getCreator()) {
                continue;
            }

            $username = strtolower($member->getUsername());
            $displayName = strtolower($member->getDisplayName() ?? '');
            $q = strtolower($query);

            if (str_contains($username, $q) || str_contains($displayName, $q)) {
                $matches[] = $member;
            }
        }

        return $this->render('dashboard/_admin_autocomplete_suggestions.html.twig', [
            'matches' => array_slice($matches, 0, 6),
            'channel' => $channel,
        ]);
    }
}
