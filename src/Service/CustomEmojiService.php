<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CustomEmoji;
use App\Repository\CustomEmojiRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Cache\CacheInterface;

class CustomEmojiService
{
    private const FILESYSTEM_CACHE_KEY = 'emojis_filesystem_list';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $defaultStorage,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly CustomEmojiRepository $emojiRepository,
    ) {}

    /**
     * @return array{emojis: array<int, array{code: string, filename: string, tags: array<int, string>}>, total: int}
     */
    public function list(string $q, int $page = 1, int $limit = 25): array
    {
        $qb = $this->emojiRepository->createQueryBuilder('e');

        if ($q !== '') {
            $qb->where('LOWER(e.code) LIKE :q OR LOWER(CAST(e.tags AS text)) LIKE :q')->setParameter(
                'q',
                '%' . mb_strtolower($q) . '%',
            );
        }

        $qb->orderBy('e.code', 'ASC');

        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);

        $paginator = new \Doctrine\ORM\Tools\Pagination\Paginator($qb->getQuery());
        $total = count($paginator);

        $matchingEmojis = [];
        foreach ($paginator as $emoji) {
            $matchingEmojis[] = [
                'code' => $emoji->getCode(),
                'filename' => $emoji->getFilename(),
                'tags' => $emoji->getTags(),
            ];
        }

        return [
            'emojis' => $matchingEmojis,
            'total' => $total,
        ];
    }

    public function upload(string $code, UploadedFile $file, string $tagsString): void
    {
        if ($code === '' || !$file) {
            throw new \InvalidArgumentException('Le code et le fichier sont obligatoires.');
        }

        if (
            $file->getMimeType() !== 'image/gif'
            || !str_ends_with(strtolower($file->getClientOriginalName()), '.gif')
        ) {
            throw new \InvalidArgumentException('Seuls les fichiers GIF sont supportés pour les émojis personnalisés.');
        }

        $sanitizedCode = preg_replace('/[^a-zA-Z0-9_\-\+:]/', '', $code);
        if ($sanitizedCode !== $code) {
            throw new \InvalidArgumentException(
                'Le code contient des caractères invalides. Utilisez des lettres, chiffres, tirets, underscores ou deux-points.',
            );
        }

        $filename = self::storageFilename($sanitizedCode);
        $storagePath = 'emojis/' . $filename;

        try {
            $content = file_get_contents($file->getPathname());
            $this->defaultStorage->write($storagePath, $content);
            $this->cache->delete(self::FILESYSTEM_CACHE_KEY);

            $tags = array_map('trim', explode(',', $tagsString));

            $customEmoji = $this->emojiRepository->findOneBy(['code' => $sanitizedCode]);
            if (!$customEmoji) {
                $customEmoji = new CustomEmoji();
                $customEmoji->setCode($sanitizedCode);
                $customEmoji->setFilename($filename);
            }
            $customEmoji->setTags($tags);

            $this->entityManager->persist($customEmoji);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur lors de l\'enregistrement de l\'émoji : ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $code): void
    {
        if ($code === '') {
            throw new \InvalidArgumentException('Émoji invalide.');
        }

        $customEmoji = $this->emojiRepository->findOneBy(['code' => $code]);
        if ($customEmoji) {
            $filename = $customEmoji->getFilename();
            $this->entityManager->remove($customEmoji);
            $this->entityManager->flush();
        } else {
            $filename = self::storageFilename($code);
        }

        $storagePath = 'emojis/' . $filename;
        try {
            if ($this->defaultStorage->has($storagePath)) {
                $this->defaultStorage->delete($storagePath);
            }
            $this->cache->delete(self::FILESYSTEM_CACHE_KEY);
        } catch (\Exception $e) {
            throw new \RuntimeException('Erreur lors de la suppression du fichier : ' . $e->getMessage(), 0, $e);
        }
    }

    public function saveTags(string $code, string $tagsString): void
    {
        if ($code === '') {
            throw new \InvalidArgumentException('Émoji invalide.');
        }

        $tags = array_map('trim', explode(',', $tagsString));

        $customEmoji = $this->emojiRepository->findOneBy(['code' => $code]);
        if (!$customEmoji) {
            $customEmoji = new CustomEmoji();
            $customEmoji->setCode($code);
            $customEmoji->setFilename(self::storageFilename($code));
        }

        $customEmoji->setTags($tags);

        $this->entityManager->persist($customEmoji);
        $this->entityManager->flush();
    }

    public function addTag(string $code, string $tag): void
    {
        if ($code === '' || $tag === '') {
            throw new \InvalidArgumentException('Émoji ou tag invalide.');
        }

        $customEmoji = $this->emojiRepository->findOneBy(['code' => $code]);
        if (!$customEmoji) {
            $customEmoji = new CustomEmoji();
            $customEmoji->setCode($code);
            $customEmoji->setFilename(self::storageFilename($code));
        }

        $tags = $customEmoji->getTags();
        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $customEmoji->setTags($tags);
            $this->entityManager->persist($customEmoji);
            $this->entityManager->flush();
        }
    }

    public function removeTag(string $code, string $tag): void
    {
        if ($code === '' || $tag === '') {
            throw new \InvalidArgumentException('Émoji ou tag invalide.');
        }

        $customEmoji = $this->emojiRepository->findOneBy(['code' => $code]);
        if ($customEmoji) {
            $tags = $customEmoji->getTags();
            $key = array_search($tag, $tags, true);
            if ($key !== false) {
                unset($tags[$key]);
                $customEmoji->setTags(array_values($tags));
                $this->entityManager->persist($customEmoji);
                $this->entityManager->flush();
            }
        }
    }

    private static function storageFilename(string $code): string
    {
        $pos = strrpos($code, ':');
        if ($pos !== false) {
            $name = substr($code, 0, $pos);
            $dir = substr($code, $pos + 1);

            return $dir . '/' . basename($name . '.gif');
        }

        return basename($code . '.gif');
    }
}
