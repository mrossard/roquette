<?php

namespace App\Controller;

use App\Entity\Invitation;

use App\Repository\ChannelRepository;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class InvitationController extends AbstractController
{
    // -------------------------------------------------------------------------
    // Send invitation
    // -------------------------------------------------------------------------

    #[Route('/channels/{slug}/invite', name: 'app_channel_invite', methods: ['POST'])]
    public function inviteUser(
        string $slug,
        Request $request,
        ChannelRepository $channelRepository,
        EntityManagerInterface $entityManager,
        MercurePublisher $mercurePublisher,
        \Psr\Log\LoggerInterface $logger
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $activeChannel = $channelRepository->findOneBy(['slug' => $slug]);
        if (!$activeChannel) {
            return new Response('Canal non trouvé.', 404);
        }

        if ($activeChannel->isDm()) {
            return new Response('Opération non autorisée pour un message direct.', 403);
        }

        if ($activeChannel->getCreator() !== $currentUser) {
            return new Response('Non autorisé.', 403);
        }

        $userId = $request->request->get('userId');
        if (!$userId) {
            return new Response('ID utilisateur manquant.', 400);
        }

        $invitedUser = $entityManager->getRepository(\App\Entity\User::class)->find($userId);
        if (!$invitedUser) {
            return new Response('Utilisateur non trouvé.', 404);
        }

        $invitation = new Invitation();
        $invitation->setChannel($activeChannel);
        $invitation->setInvitee($invitedUser);
        $entityManager->persist($invitation);
        $entityManager->flush();

        $logger->info(sprintf('User "%s" invited user "%s" to channel "%s" (slug: "%s")', $currentUser->getUsername(), $invitedUser->getUsername(), $activeChannel->getName(), $activeChannel->getSlug()));

        $sidebarHtml = $this->renderView('dashboard/_invite_sidebar_item.html.twig', [
            'invite' => $invitation,
        ]);

        $mercurePublisher->publishToUser($invitedUser, [
            'type'           => 'invitation_received',
            'invitedUsername' => $invitedUser->getUsername(),
            'invitationId'   => $invitation->getId(),
            'channelSlug'    => $activeChannel->getSlug(),
            'channelName'    => $activeChannel->getName(),
            'html'           => $sidebarHtml,
        ]);

        $usersToInvite = $entityManager->getRepository(\App\Entity\User::class)->createQueryBuilder('u')
            ->where('u.id != :currentUserId')
            ->andWhere('u.id NOT IN (
                SELECT mu.id FROM App\Entity\Channel c2 JOIN c2.members mu WHERE c2.id = :channelId
            )')
            ->andWhere('u.id NOT IN (
                SELECT IDENTITY(i.invitee) FROM App\Entity\Invitation i WHERE i.channel = :channelId
            )')
            ->setParameter('currentUserId', $currentUser->getId())
            ->setParameter('channelId', $activeChannel->getId())
            ->getQuery()
            ->getResult();

        return $this->render('dashboard/_invite_section.html.twig', [
            'activeChannel'  => $activeChannel,
            'usersToInvite'  => $usersToInvite,
            'successMessage' => sprintf('%s a été invité !', $invitedUser->getUsername()),
        ]);
    }

    // -------------------------------------------------------------------------
    // Accept invitation
    // -------------------------------------------------------------------------

    #[Route('/invitations/{id}/accept', name: 'app_invite_accept', methods: ['POST'])]
    public function acceptInvitation(int $id, EntityManagerInterface $entityManager, \Psr\Log\LoggerInterface $logger): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $invitation = $entityManager->getRepository(Invitation::class)->find($id);
        if (!$invitation) {
            return new Response('Invitation non trouvée.', 404);
        }

        if ($invitation->getInvitee() !== $currentUser) {
            return new Response('Non autorisé.', 403);
        }

        $channel = $invitation->getChannel();
        $channel->addMember($currentUser);
        $entityManager->remove($invitation);
        $entityManager->flush();

        $logger->info(sprintf('User "%s" accepted invitation to channel "%s" (slug: "%s")', $currentUser->getUsername(), $channel->getName(), $channel->getSlug()));

        return new Response(null, 204, [
            'HX-Redirect' => $this->generateUrl('app_channel', ['slug' => $channel->getSlug()])
        ]);
    }

    // -------------------------------------------------------------------------
    // Reject invitation
    // -------------------------------------------------------------------------

    #[Route('/invitations/{id}/reject', name: 'app_invite_reject', methods: ['POST'])]
    public function rejectInvitation(int $id, EntityManagerInterface $entityManager, \Psr\Log\LoggerInterface $logger): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $invitation = $entityManager->getRepository(Invitation::class)->find($id);
        if (!$invitation) {
            return new Response('Invitation non trouvée.', 404);
        }

        if ($invitation->getInvitee() !== $currentUser) {
            return new Response('Non autorisé.', 403);
        }

        $channel = $invitation->getChannel();
        $logger->info(sprintf('User "%s" rejected invitation (ID: %d) to channel "%s" (slug: "%s")', $currentUser->getUsername(), $id, $channel->getName(), $channel->getSlug()));

        $entityManager->remove($invitation);
        $entityManager->flush();

        return new Response('', 200);
    }
}
