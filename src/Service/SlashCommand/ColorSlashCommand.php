<?php

declare(strict_types=1);

namespace App\Service\SlashCommand;

use App\Entity\Channel;
use App\Entity\User;
use App\Service\SlashCommandResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class ColorSlashCommand implements SlashCommandInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Environment $twig,
    ) {}

    public function getName(): string
    {
        return 'color';
    }

    public function processPreview(string $args): ?string
    {
        return null;
    }

    public function execute(string $args, Channel $channel, User $user, ?int $workspaceId = null): SlashCommandResult
    {
        $hueVal = $args !== '' && is_numeric($args) ? (int) $args : rand(0, 360);
        if ($hueVal < 0 || $hueVal > 360) {
            return SlashCommandResult::handled(new Response('', 400));
        }

        $user->setCustomHue($hueVal);
        $this->entityManager->flush();

        $response = new Response(
            $this->twig->render('dashboard/_input_form.html.twig', ['activeChannel' => $channel]),
            200,
            ['HX-Refresh' => 'true'],
        );

        return SlashCommandResult::handled($response);
    }
}
