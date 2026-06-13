<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Channel;
use App\Entity\GroupSubscription;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\UserGroupRepository;
use App\Repository\UserRepository;
use App\Service\Group\GroupProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Service\AuditLoggerService;
use App\Enum\AuditAction;

final class AdminGroupController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly GroupProviderInterface $groupProvider,
        private readonly UserGroupRepository $userGroupRepository,
    ) {}

    #[Route('/admin/groups', name: 'app_admin_groups', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $isGlobalAdmin = $this->isGranted('ROLE_ADMIN');

        $administeredGroups = $this->userGroupRepository->findAdministeredGroupsForUser($currentUser);

        if (!$isGlobalAdmin && $administeredGroups === []) {
            throw $this->createAccessDeniedException('Accès interdit.');
        }

        $localGroups = $isGlobalAdmin ? $this->userGroupRepository->findAll() : $administeredGroups;
        $importedIdentifiers = array_map(static fn($g) => $g->getGroupIdentifier(), $localGroups);

        $searchQuery = trim($request->request->get('search', $request->query->get('search', '')));
        $providerResults = [];

        if ($searchQuery !== '' && $isGlobalAdmin) {
            $allGroups = $this->groupProvider->getGroups($searchQuery);
            foreach ($allGroups as $group) {
                $providerResults[] = [
                    'identifier' => $group->identifier,
                    'name' => $group->name,
                    'description' => $group->description,
                    'isImported' => in_array($group->identifier, $importedIdentifiers, true),
                ];
            }
        }

        return $this->render('admin/groups.html.twig', [
            'localGroups' => $localGroups,
            'providerResults' => $providerResults,
            'searchQuery' => $searchQuery,
        ]);
    }

    #[Route('/admin/groups/create', name: 'app_admin_group_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, AuditLoggerService $auditLogger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $name = trim($request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', $this->translator->trans('Le nom du groupe ne peut pas être vide.'));
            return $this->redirectToRoute('app_admin_groups');
        }

        $groupIdentifier = 'local-group-' . uniqid();

        $userGroup = new UserGroup();
        $userGroup->setName($name);
        $userGroup->setGroupIdentifier($groupIdentifier);

        // Auto-create official channel
        $channel = $this->createOfficialChannelForGroup($name, $groupIdentifier, $entityManager);
        $userGroup->setChannel($channel);
        $channel->setUserGroup($userGroup);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $userGroup->addAdministrator($currentUser);

        $entityManager->persist($userGroup);
        $entityManager->flush();

        $auditLogger->log(AuditAction::GROUP_CREATE, $currentUser, [
            'group_id' => $userGroup->getId(),
            'group_name' => $name,
            'group_identifier' => $groupIdentifier,
        ]);

        $this->addFlash('success', $this->translator->trans('Le groupe "%name%" a été créé avec son canal officiel.', [
            '%name%' => $name,
        ]));

        return $this->redirectToRoute('app_admin_groups');
    }

    #[Route('/admin/groups/import', name: 'app_admin_group_import', methods: ['POST'])]
    public function import(Request $request, EntityManagerInterface $entityManager, AuditLoggerService $auditLogger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $identifier = $request->request->get('identifier');
        $name = $request->request->get('name');

        if (!$identifier || !$name) {
            $this->addFlash('error', $this->translator->trans('Paramètres d\'import invalides.'));
            return $this->redirectToRoute('app_admin_groups');
        }

        $existing = $this->userGroupRepository->findOneBy(['groupIdentifier' => $identifier]);
        if ($existing) {
            $this->addFlash('error', $this->translator->trans('Ce groupe est déjà importé dans l\'application.'));
            return $this->redirectToRoute('app_admin_groups');
        }

        $userGroup = new UserGroup();
        $userGroup->setName($name);
        $userGroup->setGroupIdentifier($identifier);

        // Auto-create official channel
        $channel = $this->createOfficialChannelForGroup($name, $identifier, $entityManager);
        $userGroup->setChannel($channel);
        $channel->setUserGroup($userGroup);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $userGroup->addAdministrator($currentUser);

        $entityManager->persist($userGroup);
        $entityManager->flush();

        $auditLogger->log(AuditAction::GROUP_CREATE, $currentUser, [
            'group_id' => $userGroup->getId(),
            'group_name' => $name,
            'group_identifier' => $identifier,
            'imported' => true,
        ]);

        $this->addFlash('success', $this->translator->trans('Le groupe "%name%" a été importé avec son canal officiel.', [
            '%name%' => $name,
        ]));

        return $this->redirectToRoute('app_admin_groups');
    }

    #[Route('/admin/groups/{id}/delete', name: 'app_admin_group_delete', methods: ['POST'])]
    public function delete(UserGroup $userGroup, EntityManagerInterface $entityManager, AuditLoggerService $auditLogger): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $name = $userGroup->getName();
        $groupId = $userGroup->getId();
        $groupIdentifier = $userGroup->getGroupIdentifier();

        // Cascade delete on Channel is configured at DB level,
        // but we explicitly clean up subscriptions as well
        $entityManager->remove($userGroup);
        $entityManager->flush();

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $auditLogger->log(AuditAction::GROUP_DELETE, $currentUser, [
            'group_id' => $groupId,
            'group_name' => $name,
            'group_identifier' => $groupIdentifier,
        ]);

        $this->addFlash('success', $this->translator->trans('Le groupe "%name%" et son canal officiel ont été supprimés.', [
            '%name%' => $name,
        ]));

        return $this->redirectToRoute('app_admin_groups');
    }

    #[Route('/admin/groups/{id}/members/autocomplete', name: 'app_admin_group_member_autocomplete', methods: ['GET'])]
    public function memberAutocomplete(
        UserGroup $userGroup,
        Request $request,
        UserRepository $userRepository,
    ): Response {
        $this->checkAccessToGroup($userGroup);

        $query = trim($request->query->get('search', ''));
        if ($query === '') {
            return new Response(
                '<div id="member-autocomplete-suggestions" class="emoji-autocomplete-dropdown" style="display: none;"></div>',
            );
        }

        $allUsers = $userRepository->getAllSortedByDisplayName($withRobot = false);
        $currentMemberIds = array_map(static fn($u) => $u->getId(), $userGroup->getMembers()->toArray());

        $matches = [];
        $q = strtolower($query);
        foreach ($allUsers as $user) {
            if (in_array($user->getId(), $currentMemberIds, true)) {
                continue;
            }

            $username = strtolower($user->getUsername());
            $displayName = strtolower($user->getDisplayName() ?? '');

            if (str_contains($username, $q) || str_contains($displayName, $q)) {
                $matches[] = $user;
            }
        }

        return $this->render('admin/_member_autocomplete_suggestions.html.twig', [
            'matches' => array_slice($matches, 0, 6),
            'group' => $userGroup,
        ]);
    }

    #[Route('/admin/groups/{id}/members', name: 'app_admin_group_members', methods: ['GET'])]
    public function members(UserGroup $userGroup, EntityManagerInterface $entityManager): Response
    {
        $this->checkAccessToGroup($userGroup);

        $isExternal = !str_starts_with($userGroup->getGroupIdentifier(), 'local-group-');
        $externalMembers = [];

        if ($isExternal) {
            $externalUsernames = $this->groupProvider->getGroupMembers($userGroup->getGroupIdentifier());
            $registeredUsers = $entityManager->getRepository(\App\Entity\User::class)->findBy(['username' => $externalUsernames]);
            
            $registeredUsersByUsername = [];
            foreach ($registeredUsers as $u) {
                $registeredUsersByUsername[$u->getUsername()] = $u;
            }

            foreach ($externalUsernames as $username) {
                $isReg = array_key_exists($username, $registeredUsersByUsername);
                $externalMembers[] = [
                    'username' => $username,
                    'isRegistered' => $isReg,
                    'user' => $isReg ? $registeredUsersByUsername[$username] : null,
                ];
            }

            usort($externalMembers, static fn($a, $b) => strcasecmp($a['username'], $b['username']));
        }

        return $this->render('admin/group_members.html.twig', [
            'group' => $userGroup,
            'isExternal' => $isExternal,
            'externalMembers' => $externalMembers,
        ]);
    }

    #[Route('/admin/groups/{id}/members/add', name: 'app_admin_group_member_add', methods: ['POST'])]
    public function addMember(UserGroup $userGroup, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $this->checkAccessToGroup($userGroup);

        $userId = $request->request->getInt('userId');
        $user = $userRepository->find($userId);

        if (!$user) {
            $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));
            return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
        }

        $userGroup->addMember($user);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été ajouté au groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    #[Route('/admin/groups/{id}/members/{userId}/remove', name: 'app_admin_group_member_remove', methods: ['POST'])]
    public function removeMember(UserGroup $userGroup, int $userId, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $this->checkAccessToGroup($userGroup);

        $user = $userRepository->find($userId);

        if (!$user) {
            $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));
            return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
        }

        $userGroup->removeMember($user);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été retiré du groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    #[Route('/admin/groups/{id}/administrators/add', name: 'app_admin_group_administrator_add', methods: ['POST'])]
    public function addAdministrator(
        UserGroup $userGroup,
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->checkAccessToGroup($userGroup);

        $userId = $request->request->getInt('userId');
        $user = $userRepository->find($userId);

        if (!$user) {
            $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));
            return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
        }

        $userGroup->addAdministrator($user);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été promu administrateur du groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    #[Route('/admin/groups/{id}/administrators/{userId}/remove', name: 'app_admin_group_administrator_remove', methods: ['POST'])]
    public function removeAdministrator(
        UserGroup $userGroup,
        int $userId,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->checkAccessToGroup($userGroup);

        $user = $userRepository->find($userId);

        if (!$user) {
            $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));
            return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
        }

        // Prevent removing the last admin
        if ($userGroup->getAdministrators()->count() <= 1 && $userGroup->getAdministrators()->contains($user)) {
            $this->addFlash('error', $this->translator->trans('Impossible de retirer le dernier administrateur du groupe.'));
            return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
        }

        $userGroup->removeAdministrator($user);
        $entityManager->flush();

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" n\'est plus administrateur du groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    private function checkAccessToGroup(UserGroup $userGroup): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if (!$userGroup->isAdministrator($currentUser)) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas administrateur de ce groupe.');
        }
    }

    private function createOfficialChannelForGroup(string $groupName, string $groupIdentifier, EntityManagerInterface $entityManager): Channel
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $channel = new Channel();
        $channel->setName($groupName);

        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($groupName));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'group-channel-' . uniqid();
        }

        $existing = $entityManager->getRepository(Channel::class)->findOneBy(['slug' => $slug]);
        if ($existing) {
            $slug = $slug . '-' . rand(100, 999);
        }

        $channel->setSlug($slug);
        $channel->setDescription('Canal officiel du groupe ' . $groupName);
        $channel->setIsPrivate(true);
        $channel->setCreator($currentUser);
        $channel->addMember($currentUser);
        $channel->addAdministrator($currentUser);

        $entityManager->persist($channel);

        // Also add GroupSubscription so it is automatically listed and authorized
        $sub = new GroupSubscription();
        $sub->setGroupIdentifier($groupIdentifier);
        $sub->setIsGroupChannel(true);
        $channel->addGroupSubscription($sub);

        $entityManager->persist($sub);

        return $channel;
    }
}
