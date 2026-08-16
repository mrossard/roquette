<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessageManager
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MercurePublisher $mercurePublisher,
        private readonly FileUploadService $fileUploadService,
        private readonly TranslatorInterface $translator,
        private readonly MessageRenderer $messageRenderer,
        private readonly ChannelAccessService $channelAccessService,
    ) {}

    public function editMessageForm(int $id, User $currentUser): Message
    {
        $message = $this->findMessage($id);

        if ($message->getAuthor() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à modifier ce message.'));
        }

        if ($message->isPoll() && $message->getPoll()->getTotalVotes() > 0) {
            throw new BadRequestHttpException($this->translator->trans(
                'Impossible de modifier un sondage qui a déjà des votes.',
            ));
        }

        return $message;
    }

    /**
     * @param list<string> $pollOptions
     */
    public function editMessage(
        int $id,
        User $currentUser,
        string $newContent = '',
        ?string $pollQuestion = null,
        array $pollOptions = [],
        bool $pollAllowMultiple = false,
    ): string {
        $message = $this->findMessage($id);

        if ($message->getAuthor() !== $currentUser) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé à modifier ce message.'));
        }

        if ($message->isPoll()) {
            if ($message->getPoll()->getTotalVotes() > 0) {
                throw new BadRequestHttpException($this->translator->trans('Impossible de modifier un sondage qui a déjà des votes.'));
            }

            return $this->editPoll($message, $pollQuestion, $pollOptions, $pollAllowMultiple);
        }

        return $this->editText($message, $newContent);
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
        if ($channel->isTodoList()) {
            $oobHtml .= '<div id="kanban-card-' . $id . '" hx-swap-oob="delete"></div>';
        }
        $this->mercurePublisher->publishToChannel($channel, $oobHtml, 'message_' . $channel->getSlug());

        return ['success' => true];
    }

    public function toggleSaveMessage(int $id, User $currentUser): Message
    {
        $message = $this->findMessage($id);

        $channel = $message->getChannel();
        if ($channel === null || !$this->channelAccessService->canUserAccess($channel, $currentUser)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

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

    private function editText(Message $message, string $newContent): string
    {
        if (trim($newContent) === '' && !$message->getFilePath()) {
            throw new BadRequestHttpException($this->translator->trans('Le message ne peut pas être vide.'));
        }

        $message->setContent(trim($newContent) === '' ? null : $newContent);
        $message->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $renderedHtml = $this->messageRenderer->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->messageRenderer->renderFeedItem($message, ['oob' => true]);

        $this->mercurePublisher->publishToChannel(
            $message->getChannel(),
            $renderedHtmlOob,
            'message_' . $message->getChannel()->getSlug(),
        );

        return $renderedHtml;
    }

    /**
     * @param list<string> $optionsData
     */
    private function editPoll(
        Message $message,
        ?string $pollQuestion,
        array $optionsData,
        bool $allowMultiple,
    ): string {
        if ($pollQuestion === null || trim($pollQuestion) === '') {
            throw new BadRequestHttpException($this->translator->trans('La question du sondage ne peut pas être vide.'));
        }

        if (count($optionsData) < 2) {
            throw new BadRequestHttpException($this->translator->trans('Un sondage requiert au moins 2 options.'));
        }

        $poll = $message->getPoll();
        $poll->setQuestion(trim($pollQuestion));
        $poll->setAllowMultiple($allowMultiple);

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

        $renderedHtml = $this->messageRenderer->renderFeedItem($message, ['no_fade' => true]);

        $renderedHtmlOob = $this->messageRenderer->renderFeedItem($message, ['oob' => true]);

        $this->mercurePublisher->publishToChannel(
            $message->getChannel(),
            $renderedHtmlOob,
            'message_' . $message->getChannel()->getSlug(),
        );

        return $renderedHtml;
    }
}

