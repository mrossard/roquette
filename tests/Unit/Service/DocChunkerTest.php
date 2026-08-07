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
        @unlink($this->projectDir . '/DOC_UTILISATEUR.md');
        @rmdir($this->projectDir);
    }

    public function testMissingDocReturnsEmpty(): void
    {
        $this->assertSame([], (new DocChunker())->chunk($this->projectDir));
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

        $chunks = (new DocChunker())->chunk($this->projectDir);

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString('Bienvenue sur Roquette.', $chunks[0]->getMetadata()->getText());
        $this->assertSame('# Guide', $chunks[0]->getMetadata()->getTitle());
        $this->assertStringContainsString('Utilisez Roquette pour discuter', $chunks[1]->getMetadata()->getText());
        $this->assertStringNotContainsString('*', $chunks[1]->getMetadata()->getText());
        $this->assertStringNotContainsString('```', $chunks[1]->getMetadata()->getText());
        $this->assertStringContainsString('Cliquez ici pour continuer.', $chunks[2]->getMetadata()->getText());
        $this->assertStringNotContainsString('Table des matières', implode(' ', array_map(
            static fn($d) => (string) $d->getMetadata()->getTitle(),
            $chunks,
        )));
    }
}
