<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\DocumentTextExtractor;
use App\Service\FileUploadService;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Document as PdfDocument;
use Smalot\PdfParser\Parser as PdfParser;

final class DocumentTextExtractorTest extends TestCase
{
    public function testIsSupported(): void
    {
        $fileUploadService = $this->createStub(FileUploadService::class);
        $extractor = new DocumentTextExtractor($fileUploadService);

        static::assertTrue($extractor->isSupported('application/pdf', 'doc.pdf'));
        static::assertTrue($extractor->isSupported('text/plain', 'note.txt'));
        static::assertTrue($extractor->isSupported('text/markdown', 'readme.md'));
        static::assertTrue($extractor->isSupported('application/json', 'data.json'));
        static::assertTrue($extractor->isSupported(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'report.docx',
        ));
        static::assertTrue($extractor->isSupported(null, 'script.py'));
        static::assertTrue($extractor->isSupported(null, 'query.sql'));

        // Unsupported
        static::assertFalse($extractor->isSupported('image/png', 'photo.png'));
        static::assertFalse($extractor->isSupported('audio/mpeg', 'song.mp3'));
        static::assertFalse($extractor->isSupported('video/mp4', 'movie.mp4'));
    }

    public function testExtractPlainText(): void
    {
        $fileUploadService = $this->createStub(FileUploadService::class);
        $fileUploadService->method('exists')->willReturn(true);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "Bonjour, ceci est un document de test.\n\nContenu important.");
        rewind($stream);

        $fileUploadService->method('readStream')->willReturn($stream);

        $extractor = new DocumentTextExtractor($fileUploadService);
        $text = $extractor->extractText('notes.txt', 'text/plain', 'notes.txt');

        static::assertNotNull($text);
        static::assertStringContainsString('Bonjour, ceci est un document de test.', $text);
        static::assertStringContainsString('Contenu important.', $text);
    }

    public function testExtractHtmlTextStripsTags(): void
    {
        $fileUploadService = $this->createStub(FileUploadService::class);
        $fileUploadService->method('exists')->willReturn(true);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '<h1>Titre</h1><p>Paragraphe avec du <strong>texte en gras</strong>.</p>');
        rewind($stream);

        $fileUploadService->method('readStream')->willReturn($stream);

        $extractor = new DocumentTextExtractor($fileUploadService);
        $text = $extractor->extractText('page.html', 'text/html', 'page.html');

        static::assertNotNull($text);
        static::assertStringContainsString('Titre', $text);
        static::assertStringContainsString('Paragraphe avec du texte en gras.', $text);
        static::assertStringNotContainsString('<h1>', $text);
        static::assertStringNotContainsString('<strong>', $text);
    }

    public function testExtractPdfText(): void
    {
        $fileUploadService = $this->createStub(FileUploadService::class);
        $fileUploadService->method('exists')->willReturn(true);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, '%PDF-1.4 dummy pdf content');
        rewind($stream);

        $fileUploadService->method('readStream')->willReturn($stream);

        $pdfDoc = $this->createStub(PdfDocument::class);
        $pdfDoc->method('getText')->willReturn("Facture N° 12345\nTotal TTC : 1 200,00 €");

        $pdfParser = $this->createStub(PdfParser::class);
        $pdfParser->method('parseContent')->willReturn($pdfDoc);

        $extractor = new DocumentTextExtractor($fileUploadService, null, $pdfParser);
        $text = $extractor->extractText('invoice.pdf', 'application/pdf', 'invoice.pdf');

        static::assertNotNull($text);
        static::assertStringContainsString('Facture N° 12345', $text);
        static::assertStringContainsString('Total TTC : 1 200,00 €', $text);
    }

    public function testChunkTextSingleChunk(): void
    {
        $fileUploadService = $this->createStub(FileUploadService::class);
        $extractor = new DocumentTextExtractor($fileUploadService);

        $text = 'Ceci est un texte court.';
        $chunks = $extractor->chunkText($text, 1000, 100);

        static::assertCount(1, $chunks);
        static::assertSame('Ceci est un texte court.', $chunks[0]);
    }

    public function testChunkTextMultipleChunksWithOverlap(): void
    {
        $fileUploadService = $this->createStub(FileUploadService::class);
        $extractor = new DocumentTextExtractor($fileUploadService);

        $text = str_repeat('Mot ', 100); // ~400 characters
        $chunks = $extractor->chunkText($text, 100, 20);

        static::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            static::assertLessThanOrEqual(100, mb_strlen($chunk, 'UTF-8'));
        }
    }
}
