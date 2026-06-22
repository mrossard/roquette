<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\GenerateExportMessage;
use App\Repository\ChannelRepository;
use App\Repository\UserRepository;
use App\Service\ChannelExportService;
use App\Service\MessagePublishService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateExportMessageHandler
{
    public function __construct(
        private ChannelRepository $channelRepository,
        private UserRepository $userRepository,
        private ChannelExportService $channelExportService,
        private MessagePublishService $messagePublishService,
    ) {}

    public function __invoke(GenerateExportMessage $message): void
    {
        $channel = $this->channelRepository->find($message->getChannelId());
        $user = $this->userRepository->find($message->getUserId());

        if (!$channel || !$user) {
            return;
        }

        // Generate the export using ChannelExportService
        $export = $this->channelExportService->generate($channel, $user);

        // Find the Robot Roquette user
        $robotUser = $this->userRepository->findOneBy(['username' => User::ROBOT_USERNAME]);
        if (!$robotUser) {
            return;
        }

        // Find the DM channel for this user and robot-roquette
        $dmChannelSlug = 'dm-robot-roquette-' . $user->getSlug();
        $dmChannel = $this->channelRepository->findOneBy(['slug' => $dmChannelSlug]);

        if (!$dmChannel) {
            return;
        }

        // Build the message in French
        $content = sprintf(
            "L'export du canal **#%s** est disponible. [Télécharger l'archive](/exports/%d/download)",
            $channel->getName(),
            $export->getId(),
        );

        // Publish message using the message publish service
        $this->messagePublishService->publish($dmChannel, $robotUser, $content);
    }
}
