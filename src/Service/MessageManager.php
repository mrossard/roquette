<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MessageManager
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly FileUploadService $fileUploadService,
        private readonly TranslatorInterface $translator,
        private readonly Environment $twig,
    ) {}

    public function editMessageForm(int $id, User $currentUser): array
    {
        $message = $this->findMessage($id);

        if ($message->getAuthor() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à modifier ce message.'));
        }

        if ($message->isPoll() && $message->getPoll()->getTotalVotes() > 0) {
            throw new BadRequestHttpException(
                $this->translator->trans('Impossible de modifier un sondage qui a déjà des votes.'),
            );
        }

        return ['message' => $message];
    }

    public function editMessage(int $id, Request $request, User $currentUser): array
    {
        $message = $this->findMessage($id);

        if ($message->getAuthor() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à modifier ce message.'));
        }

        if ($message->isPoll()) {
            if ($message->getPoll()->getTotalVotes() > 0) {
                return [
                    'error' => $this->translator->trans('Impossible de modifier un sondage qui a déjà des votes.'),
                    'statusCode' => 400,
                ];
            }

            return $this->editPoll($message, $request, $currentUser);
        }

        return $this->editText($message, $request, $currentUser);
    }

    public function deleteMessage(int $id, User $currentUser): array
    {
        $message = $this->findMessage($id);
        $channel = $message->getChannel();

        if ($message->getAuthor() !== $currentUser && $channel->getCreator() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à supprimer ce message.'));
        }

        $oobHtml = '';

        if ($channel->getPinnedMessage() === $message) {
            $channel->setPinnedMessage(null);
            $oobHtml .= '<div id="pinned-banner-container" hx-swap-oob="true"></div>';
        }

        if ($message->getFilePath()) {
            $this->fileUploadService->delete($message->getFilePath());
        }

        $this->entityManager->remove($message);
        $this->entityManager->flush();

        $oobHtml .= '<div id="feed-item-' . $id . '" hx-swap-oob="delete"></div>';
        $this->mercurePublisher->publishToChannel($channel, $oobHtml, 'message_' . $channel->getSlug());

        return ['success' => true];
    }

    public function toggleSaveMessage(int $id, User $currentUser): Message
    {
        $message = $this->findMessage($id);

        if ($currentUser->getSavedMessages()->contains($message)) {
            $currentUser->removeSavedMessage($message);
        } else {
            $currentUser->addSavedMessage($message);
        }

        $this->entityManager->flush();

        return $message;
    }

    public function findMessage(int $id): Message
    {
        $message = $this->messageRepository->find($id);
        if (!$message) {
            throw new NotFoundHttpException($this->translator->trans('Message non trouvé.'));
        }

        return $message;
    }

    private function editText(Message $message, Request $request, User $currentUser): array
    {
        $newContent = $request->request->get('content', '');
        if (trim($newContent) === '' && !$message->getFilePath()) {
            return [
                'error' => $this->translator->trans('Le message ne peut pas être vide.'),
                'statusCode' => 400,
            ];
        }

        $message->setContent(trim($newContent) === '' ? null : $newContent);
        $message->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $renderedHtml = $this->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->twig->render('dashboard/_feed_item.html.twig', array_merge(
            $this->feedItemParams($message),
            ['oob' => true],
        ));

        $this->mercurePublisher->publishToChannel(
            $message->getChannel(),
            $renderedHtmlOob,
            'message_' . $message->getChannel()->getSlug(),
        );

        return ['renderedHtml' => $renderedHtml];
    }

    private function editPoll(Message $message, Request $request, User $currentUser): array
    {
        $pollQuestion = $request->request->get('poll_question');
        $optionsData = $this->getPollOptions($request);

        if ($pollQuestion === null || trim($pollQuestion) === '') {
            return [
                'error' => $this->translator->trans('La question du sondage ne peut pas être vide.'),
                'statusCode' => 400,
            ];
        }

        if (count($optionsData) < 2) {
            return [
                'error' => $this->translator->trans('Un sondage requiert au moins 2 options.'),
                'statusCode' => 400,
            ];
        }

        $poll = $message->getPoll();
        $poll->setQuestion(trim($pollQuestion));
        $poll->setAllowMultiple((bool) $request->request->get('allow_multiple'));

        $existingOptions = $poll->getOptions()->getValues();
        $position = 0;
        foreach ($optionsData as $idx => $optText) {
            if (array_key_exists($idx, $existingOptions)) {
                if ($existingOptions[$idx]->getText() !== $optText) {
                    $existingOptions[$idx]->setText($optText);
                    $existingOptions[$idx]->getVotes()->clear();
                }
                $existingOptions[$idx]->setPosition($position++);
            } else {
                $newOption = new \App\Entity\PollOption();
                $newOption->setText($optText);
                $newOption->setPosition($position++);
                $poll->addOption($newOption);
            }
        }

        for ($i = count($optionsData); $i < count($existingOptions); $i++) {
            $poll->removeOption($existingOptions[$i]);
        }

        $message->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $renderedHtml = $this->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->twig->render('dashboard/_feed_item.html.twig', array_merge(
            $this->feedItemParams($message),
            ['oob' => true],
        ));

        $this->mercurePublisher->publishToChannel(
            $message->getChannel(),
            $renderedHtmlOob,
            'message_' . $message->getChannel()->getSlug(),
        );

        return ['renderedHtml' => $renderedHtml];
    }

    private function renderFeedItem(Message $message, array $extraParams = []): string
    {
        return $this->twig->render('dashboard/_feed_item.html.twig', array_merge(
            $this->feedItemParams($message),
            $extraParams,
        ));
    }

    private function feedItemParams(Message $message): array
    {
        return [
            'author' => $message->getAuthor(),
            'message' => $message->getContent(),
            'timestamp' => $message->getCreatedAt(),
            'message_id' => $message->getId(),
            'updated_at' => $message->getUpdatedAt(),
            'fileName' => $message->getFileName(),
            'fileSize' => $message->getFileSize(),
            'filePath' => $message->getFilePath(),
            'mimeType' => $message->getMimeType(),
            'messageObject' => $message,
        ];
    }

    /** @return string[] */
    private function getPollOptions(Request $request): array
    {
        $optionsData = $request->request->all()['poll_options'] ?? [];
        if (!is_array($optionsData)) {
            return [];
        }

        return array_filter(array_map('trim', $optionsData), static fn($val) => $val !== '');
    }
}
