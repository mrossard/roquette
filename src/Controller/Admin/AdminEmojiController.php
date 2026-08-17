<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\CustomEmojiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class AdminEmojiController extends AbstractController
{
    use AdminPaginationTrait;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/admin/emojis', name: 'app_admin_emojis')]
    public function emojis(Request $request, CustomEmojiService $emojiService): Response
    {
        $page = $this->getPage($request);
        $q = trim($request->query->get('q', ''));

        $result = $emojiService->list($q, $page, self::ADMIN_PER_PAGE);
        $totalPages = $this->calculateTotalPages($result['total']);

        return $this->render('admin/emojis.html.twig', [
            'emojis' => $result['emojis'],
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $result['total'],
            'q' => $q,
        ]);
    }

    #[Route('/admin/emojis/edit', name: 'app_admin_emojis_edit', methods: ['POST'])]
    public function editEmoji(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');
        $tagsString = $request->request->get('tags', '');

        if ($code === '') {
            $this->addFlash('error', $this->translator->trans('Émoji invalide.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        try {
            $emojiService->saveTags($code, $tagsString);
            $this->addFlash('success', $this->translator->trans('Tags mis à jour pour l\'émoji %code%.', [
                '%code%' => $code,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    #[Route('/admin/emojis/upload', name: 'app_admin_emojis_upload', methods: ['POST'])]
    public function uploadEmoji(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = trim($request->request->get('code', ''));
        $file = $request->files->get('emoji_file');

        if ($code === '' || !$file) {
            $this->addFlash('error', $this->translator->trans('Le code et le fichier sont obligatoires.'));
            return $this->redirectToRoute('app_admin_emojis');
        }

        try {
            $emojiService->upload($code, $file, $request->request->get('tags', ''));
            $this->addFlash('success', $this->translator->trans('Émoji %code% ajouté avec succès.', [
                '%code%' => $code,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Erreur lors de l\'enregistrement de l\'émoji : %error%', [
                '%error%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('app_admin_emojis');
    }

    #[Route('/admin/emojis/delete', name: 'app_admin_emojis_delete', methods: ['POST'])]
    public function deleteEmoji(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');

        try {
            $emojiService->delete($code);
            $this->addFlash('success', $this->translator->trans('L\'émoji %code% a été supprimé.', [
                '%code%' => $code,
            ]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $this->translator->trans('Erreur lors de la suppression du fichier : %error%', [
                '%error%' => $e->getMessage(),
            ]));
        }

        return $this->redirectToRoute('app_admin_emojis');
    }

    #[Route('/admin/emojis/add-tag', name: 'app_admin_emojis_add_tag', methods: ['POST'])]
    public function addTag(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');
        $newTag = trim($request->request->get('tag', ''));

        try {
            $emojiService->addTag($code, $newTag);
            $this->addFlash('success', $this->translator->trans('Tag "%tag%" ajouté.', ['%tag%' => $newTag]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }

    #[Route('/admin/emojis/remove-tag', name: 'app_admin_emojis_remove_tag', methods: ['POST'])]
    public function removeTag(Request $request, CustomEmojiService $emojiService): Response
    {
        $code = $request->request->get('code', '');
        $tagToRemove = trim($request->request->get('tag', ''));

        try {
            $emojiService->removeTag($code, $tagToRemove);
            $this->addFlash('success', $this->translator->trans('Tag "%tag%" retiré.', ['%tag%' => $tagToRemove]));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_admin_emojis', $request->query->all());
    }
}
