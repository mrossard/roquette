<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\Trait\RequestValidationTrait;
use App\Entity\Channel;
use App\Entity\User;
use App\Repository\ChannelRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MessagePublisher
{
    use RequestValidationTrait;

    public function __construct(
        private readonly ChannelRepository $channelRepository,
        private readonly MessagePublishService $publishService,
        private readonly SlashCommandHandler $slashCommandHandler,
        private readonly RequestStack $requestStack,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.message_api')]
        private readonly RateLimiterFactoryInterface $rateLimiter,
    ) {}

    public function publish(string $slug, Request $request, User $currentUser): Response
    {
        $channel = $this->findChannel($slug, $currentUser);

        $limiter = $this->rateLimiter->create('user_' . $currentUser->getId());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->addFlash('error', $this->translator->trans('Trop de messages envoyés. Veuillez patienter.'));

            return $this->renderForm($channel, Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($this->isPostMaxSizeExceeded($request)) {
            $this->addFlash(
                'error',
                $this->translator->trans(
                    'Le fichier est trop volumineux pour être envoyé (limite post_max_size dépassée).',
                ),
            );

            return $this->renderForm($channel);
        }

        $messageText = $request->request->get('message', '');
        $uploadedFile = $request->files->get('file');
        $pollQuestion = $request->request->get('poll_question');

        if (trim($messageText) === '' && !$uploadedFile && ($pollQuestion === null || $pollQuestion === '')) {
            return $this->renderForm($channel);
        }

        // Handle slash commands that return a direct Response
        if ($pollQuestion === null && !$uploadedFile && str_starts_with(trim($messageText), '/')) {
            $slashResponse = $this->slashCommandHandler->process($messageText, $channel, $currentUser);
            if ($slashResponse !== null) {
                return $slashResponse;
            }

            // $messageText may have been mutated by /shrug or /me
        }

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $currentUser,
            messageText: $messageText,
            file: $uploadedFile,
            pollQuestion: $pollQuestion,
            pollOptions: $this->getPollOptions($request),
            pollAllowMultiple: (bool) $request->request->get('allow_multiple'),
            replyToId: ($replyTo = $request->request->get('replyTo')) ? (int) $replyTo : null,
        );

        if (!$result->success) {
            if ($result->error !== null && $result->statusCode === 400) {
                return new Response($result->error, 400);
            }

            if ($result->error !== null) {
                $this->addFlash('error', $result->error);
            }

            return $this->renderForm($channel, $result->statusCode ?? 200);
        }

        return $this->renderForm($channel);
    }

    private function findChannel(string $slug, User $currentUser): Channel
    {
        $channel = $this->channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw new NotFoundHttpException($this->translator->trans('Canal non trouvé.'));
        }

        if (!$this->channelRepository->canUserAccess($channel, $currentUser)) {
            throw new AccessDeniedHttpException($this->translator->trans('Non autorisé.'));
        }

        return $channel;
    }

    private function renderForm(Channel $channel, int $statusCode = 200): Response
    {
        return new Response($this->twig->render('dashboard/_input_form.html.twig', [
            'activeChannel' => $channel,
        ]), $statusCode);
    }

    private function addFlash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();
        if ($session !== null && $session->isStarted()) {
            $session->getFlashBag()->add($type, $message);
        }
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
