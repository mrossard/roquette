<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\DocumentContextBuilder;
use App\Service\DocChunker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AllowMockObjectsWithoutExpectations]
final class DocumentContextBuilderTest extends TestCase
{
    private function buildContext(?iterable $retrieved, bool $throw = false): string
    {
        $retriever = $this->createMock(RetrieverInterface::class);
        if ($throw) {
            $retriever->method('retrieve')->willThrowException(new \RuntimeException('store down'));
        } else {
            $retriever->method('retrieve')->willReturn($retrieved ?? []);
        }

        $chunker = new DocChunker();
        $projectDir = sys_get_temp_dir() . '/doc_ctx_' . uniqid();
        mkdir($projectDir);
        file_put_contents($projectDir . '/DOC_UTILISATEUR.md', <<<MD
            # Guide

            Intro du guide.

            ## 1. Présentation

            Contenu de présentation.
            MD);

        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturnCallback(static fn(string $name): ?string => $name
            === 'kernel.project_dir'
                ? $projectDir
                : null);

        $builder = new DocumentContextBuilder(
            $retriever,
            $chunker,
            $this->createMock(LoggerInterface::class),
            $parameterBag,
        );

        $result = $builder->buildContext('question');

        if (is_file($projectDir . '/DOC_UTILISATEUR.md')) {
            unlink($projectDir . '/DOC_UTILISATEUR.md');
        }
        if (is_dir($projectDir)) {
            rmdir($projectDir);
        }

        return $result;
    }

    public function testRetrievedDocsAreUsed(): void
    {
        $doc = new TextDocument('doc-1', 'contenu récupéré', new Metadata([
            '_title' => 'Section A',
            '_text' => 'contenu récupéré',
        ]));

        $context = $this->buildContext([$doc]);

        static::assertStringContainsString('### Section A', $context);
        static::assertStringContainsString('contenu récupéré', $context);
        static::assertStringNotContainsString('Intro du guide', $context);
    }

    public function testFallsBackToChunkedDocWhenEmpty(): void
    {
        $context = $this->buildContext([]);

        static::assertStringContainsString('Intro du guide.', $context);
        static::assertStringContainsString('Contenu de présentation.', $context);
    }

    public function testFallsBackToChunkedDocOnFailure(): void
    {
        $context = $this->buildContext(null, throw: true);

        static::assertStringContainsString('Intro du guide.', $context);
    }
}
