<?php

declare(strict_types=1);

namespace App\Service;

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

/**
 * Handles the HTTP form submission workflow for publishing messages:
 * validates permissions, rate limiting, request payload, slash commands,
 * and renders HTMX response fragments.
 */
class MessageSubmissionHandler
{
    public function __construct(
        private readonly ChannelRepository $channelRepository,
        private readonly ChannelAccessService $channelAccessService,
        private readonly MessagePublishService $publishService,
        private readonly SlashCommandHandler $slashCommandHandler,
        private readonly RequestStack $requestStack,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.message_api')]
        private readonly RateLimiterFactoryInterface $rateLimiter,
    ) {}

    public function handle(string $slug, Request $request, User $currentUser): Response
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

        $messageText = (string) $request->request->get('message', '');
        $uploadedFile = $request->files->get('file');
        $pollQuestion = $request->request->get('poll_question');
        $isPoll = $pollQuestion !== null && $pollQuestion !== '';

        if (trim($messageText) === '' && !$uploadedFile && !$isPoll) {
            return $this->renderForm($channel);
        }

        // Handle slash commands that return a direct Response
        if (!$isPoll && !$uploadedFile && str_starts_with(trim($messageText), '/')) {
            $slashResult = $this->slashCommandHandler->process(
                $messageText,
                $channel,
                $currentUser,
                $this->getCurrentWorkspaceId($request),
            );
            if ($slashResult->response !== null) {
                return $slashResult->response;
            }

            $messageText = $slashResult->messageText;
        }

        $result = $this->publishService->publish(
            channel: $channel,
            currentUser: $currentUser,
            messageText: $messageText,
            file: $uploadedFile,
            pollQuestion: $pollQuestion !== null ? (string) $pollQuestion : null,
            pollOptions: $this->parsePollOptions($request),
            pollAllowMultiple: (bool) $request->request->get('allow_multiple'),
            replyToId: ($replyTo = $request->request->get('replyTo')) ? (int) $replyTo : null,
            workspaceId: $this->getCurrentWorkspaceId($request),
        );

        if (!$result->success) {
            if ($result->error !== null) {
                $this->addFlash('error', $result->error);
            }

            return $this->renderForm($channel, $result->statusCode ?? 422);
        }

        return $this->renderForm($channel);
    }

    private function getCurrentWorkspaceId(Request $request): ?int
    {
        $session = $request->getSession();
        if ($session === null || !$session->isStarted()) {
            return null;
        }

        $workspaceId = $session->get('current_workspace_id');

        return is_int($workspaceId) ? $workspaceId : null;
    }

    private function findChannel(string $slug, User $currentUser): Channel
    {
        $channel = $this->channelRepository->findOneBy(['slug' => $slug]);
        if (!$channel) {
            throw new NotFoundHttpException($this->translator->trans('Canal non trouvé.'));
        }

        if (!$this->channelAccessService->canUserAccess($channel, $currentUser)) {
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

    /**
     * Checks if a POST request exceeded the PHP post_max_size configuration.
     * Symfony returns empty request and files parameters if post_max_size is exceeded.
     */
    private function isPostMaxSizeExceeded(Request $request): bool
    {
        return (
            $request->isMethod('POST')
            && count($request->request) === 0
            && count($request->files) === 0
            && (int) $request->headers->get('CONTENT_LENGTH', 0) > 0
        );
    }

    /**
     * Extracts non-empty poll options from a request.
     *
     * @return list<string>
     */
    private function parsePollOptions(Request $request): array
    {
        $optionsData = $request->request->all('poll_options');
        if (!is_array($optionsData)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $optionsData), static fn($val) => $val !== ''));
    }
}
