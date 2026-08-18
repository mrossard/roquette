<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Group\CreateAdminGroupDto;
use App\Dto\Group\ImportAdminGroupDto;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Repository\UserGroupRepository;
use App\Repository\UserRepository;
use App\Security\Voter\UserGroupVoter;
use App\Service\Group\GroupProviderInterface;
use App\Service\Group\UserGroupManager;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminGroupController extends AbstractController
{
    use AdminPaginationTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly GroupProviderInterface $groupProvider,
        private readonly UserGroupRepository $userGroupRepository,
        private readonly UserGroupManager $userGroupManager,
    ) {}

    #[Route('/admin/groups', name: 'app_admin_groups', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $isGlobalAdmin = $this->isGranted('ROLE_ADMIN');

        $administeredGroups = $this->userGroupRepository->findAdministeredGroupsForUser($currentUser);

        if (!$isGlobalAdmin && $administeredGroups === []) {
            throw $this->createAccessDeniedException('Accès interdit.');
        }

        $page = $this->getPage($request);

        $localGroups = $isGlobalAdmin
            ? $this->userGroupRepository->findPaginatedAll($page)
            : $this->userGroupRepository->findPaginatedAdministeredGroupsForUser($currentUser, $page);
        $totalGroups = $isGlobalAdmin
            ? $this->userGroupRepository->countAll()
            : $this->userGroupRepository->countAdministeredGroupsForUser($currentUser);

        $totalPages = $this->calculateTotalPages($totalGroups);
        $importedIdentifiers = array_map(static fn($g) => $g->getGroupIdentifier(), $localGroups);

        $searchQuery = trim((string) $request->request->get('search', (string) $request->query->get('search', '')));
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
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $totalGroups,
        ]);
    }

    #[Route('/admin/groups/create', name: 'app_admin_group_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $dto = CreateAdminGroupDto::fromRequest($request);
        if (!$dto->isValid()) {
            $this->addFlash('error', $this->translator->trans('Le nom du groupe ne peut pas être vide.'));

            return $this->redirectToRoute('app_admin_groups');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $userGroup = $this->userGroupManager->createLocalGroup($dto->name, $currentUser);
            $this->addFlash('success', $this->translator->trans('Le groupe "%name%" a été créé avec son espace de travail.', [
                '%name%' => $userGroup->getName(),
            ]));
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_groups');
    }

    #[Route('/admin/groups/import', name: 'app_admin_group_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $dto = ImportAdminGroupDto::fromRequest($request);
        if (!$dto->isValid()) {
            $this->addFlash('error', $this->translator->trans('L\'identifiant et le nom du groupe sont requis.'));

            return $this->redirectToRoute('app_admin_groups');
        }

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        try {
            $userGroup = $this->userGroupManager->importGroup($dto->identifier, $dto->name, $currentUser);
            $this->addFlash('success', $this->translator->trans('Le groupe "%name%" a été importé avec son espace de travail.', [
                '%name%' => $userGroup->getName(),
            ]));
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_groups');
    }

    #[Route('/admin/groups/{id}/delete', name: 'app_admin_group_delete', methods: ['POST'])]
    public function delete(UserGroup $userGroup): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::DELETE, $userGroup);

        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $name = $userGroup->getName();

        $this->userGroupManager->deleteGroup($userGroup, $currentUser);

        $this->addFlash('success', $this->translator->trans('Le groupe "%name%" et son espace de travail ont été supprimés.', [
            '%name%' => $name,
        ]));

        return $this->redirectToRoute('app_admin_groups');
    }

    #[Route('/admin/groups/{id}/members/autocomplete', name: 'app_admin_group_member_autocomplete', methods: ['GET'])]
    public function memberAutocomplete(UserGroup $userGroup, Request $request): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::MANAGE, $userGroup);

        $query = (string) $request->query->get('search', '');
        if (trim($query) === '') {
            return new Response(
                '<div id="member-autocomplete-suggestions" class="emoji-autocomplete-dropdown" style="display: none;"></div>',
            );
        }

        $matches = $this->userGroupManager->searchInvitableMembers($userGroup, $query);

        return $this->render('admin/_member_autocomplete_suggestions.html.twig', [
            'matches' => $matches,
            'group' => $userGroup,
        ]);
    }

    #[Route('/admin/groups/{id}/members', name: 'app_admin_group_members', methods: ['GET'])]
    public function members(UserGroup $userGroup): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::MANAGE, $userGroup);

        $isExternal = !str_starts_with($userGroup->getGroupIdentifier(), 'local-group-');
        $externalMembers = $this->userGroupManager->getExternalGroupMembers($userGroup);

        return $this->render('admin/group_members.html.twig', [
            'group' => $userGroup,
            'isExternal' => $isExternal,
            'externalMembers' => $externalMembers,
        ]);
    }

    #[Route('/admin/groups/{id}/members/add', name: 'app_admin_group_member_add', methods: ['POST'])]
    public function addMember(UserGroup $userGroup, Request $request, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::MANAGE, $userGroup);

        $user = $this->findUserOrNull($request->request->getInt('userId'), $userRepository);
        if (!$user) {
            return $this->userNotFoundResponse($userGroup);
        }

        $this->userGroupManager->addMember($userGroup, $user);

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été ajouté au groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    #[Route('/admin/groups/{id}/members/{userId}/remove', name: 'app_admin_group_member_remove', methods: ['POST'])]
    public function removeMember(UserGroup $userGroup, int $userId, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::MANAGE, $userGroup);

        $user = $this->findUserOrNull($userId, $userRepository);
        if (!$user) {
            return $this->userNotFoundResponse($userGroup);
        }

        $this->userGroupManager->removeMember($userGroup, $user);

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été retiré du groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    #[Route('/admin/groups/{id}/administrators/add', name: 'app_admin_group_administrator_add', methods: ['POST'])]
    public function addAdministrator(UserGroup $userGroup, Request $request, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::MANAGE, $userGroup);

        $user = $this->findUserOrNull($request->request->getInt('userId'), $userRepository);
        if (!$user) {
            return $this->userNotFoundResponse($userGroup);
        }

        $this->userGroupManager->addAdministrator($userGroup, $user);

        $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" a été promu administrateur du groupe.', [
            '%username%' => $user->getUsername(),
        ]));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    #[Route(
        '/admin/groups/{id}/administrators/{userId}/remove',
        name: 'app_admin_group_administrator_remove',
        methods: ['POST'],
    )]
    public function removeAdministrator(UserGroup $userGroup, int $userId, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted(UserGroupVoter::MANAGE, $userGroup);

        $user = $this->findUserOrNull($userId, $userRepository);
        if (!$user) {
            return $this->userNotFoundResponse($userGroup);
        }

        try {
            $this->userGroupManager->removeAdministrator($userGroup, $user);
            $this->addFlash('success', $this->translator->trans('L\'utilisateur "%username%" n\'est plus administrateur du groupe.', [
                '%username%' => $user->getUsername(),
            ]));
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }

    private function findUserOrNull(int $userId, UserRepository $userRepository): ?User
    {
        return $userId > 0 ? $userRepository->find($userId) : null;
    }

    private function userNotFoundResponse(UserGroup $userGroup): Response
    {
        $this->addFlash('error', $this->translator->trans('Utilisateur non trouvé.'));

        return $this->redirectToRoute('app_admin_group_members', ['id' => $userGroup->getId()]);
    }
}
