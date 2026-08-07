<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DocChunker;
use PHPUnit\Framework\TestCase;

final class DocChunkerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/doc_chunker_' . uniqid();
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $docFile = $this->projectDir . '/DOC_UTILISATEUR.md';
        if (file_exists($docFile)) {
            unlink($docFile);
        }
        if (is_dir($this->projectDir)) {
            rmdir($this->projectDir);
        }
    }

    public function testMissingDocReturnsEmpty(): void
    {
        static::assertSame([], new DocChunker()->chunk($this->projectDir));
    }

    public function testChunksSectionsAndCleansMarkdown(): void
    {
        file_put_contents($this->projectDir . '/DOC_UTILISATEUR.md', <<<MD
            # Guide

            Bienvenue sur **Roquette**.

            ## Table des matières

            1. [Un lien](#x)

            ## 1. Présentation

            Utilisez *Roquette* pour discuter avec des \`collègues\`.

            ## 2. Prise en main

            [Cliquez ici](https://example.com) pour continuer.
            MD);

        $chunks = new DocChunker()->chunk($this->projectDir);

        static::assertCount(3, $chunks);
        static::assertStringContainsString('Bienvenue sur Roquette.', $chunks[0]->getMetadata()->getText());
        static::assertSame('# Guide', $chunks[0]->getMetadata()->getTitle());
        static::assertStringContainsString('Utilisez Roquette pour discuter', $chunks[1]->getMetadata()->getText());
        static::assertStringNotContainsString('*', $chunks[1]->getMetadata()->getText());
        static::assertStringNotContainsString('```', $chunks[1]->getMetadata()->getText());
        static::assertStringContainsString('Cliquez ici pour continuer.', $chunks[2]->getMetadata()->getText());
        static::assertStringNotContainsString('Table des matières', implode(' ', array_map(
            static fn($d) => (string) $d->getMetadata()->getTitle(),
            $chunks,
        )));
    }
}
