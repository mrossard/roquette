<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Extracts and chunks text from uploaded attachments (PDF, DOCX, text, code, JSON, etc.)
 * for semantic and full-text search indexing.
 */
class DocumentTextExtractor
{
    private const int MAX_EXTRACTED_CHARS = 500_000;
    private const int DEFAULT_CHUNK_SIZE = 1200;
    private const int DEFAULT_CHUNK_OVERLAP = 200;

    public function __construct(
        private readonly FileUploadService $fileUploadService,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?PdfParser $pdfParser = null,
    ) {}

    /**
     * Determines whether the given MIME type or file name is eligible for text extraction.
     */
    public function isSupported(?string $mimeType, ?string $fileName): bool
    {
        if ($mimeType !== null && $this->isSupportedMimeType($mimeType)) {
            return true;
        }

        if ($fileName !== null && $this->isSupportedExtension(pathinfo($fileName, PATHINFO_EXTENSION))) {
            return true;
        }

        return false;
    }

    /**
     * Extracts text content from a stored file path.
     */
    public function extractText(string $filePath, ?string $mimeType = null, ?string $fileName = null): ?string
    {
        if (!$this->fileUploadService->exists($filePath)) {
            return null;
        }

        $extension = $fileName !== null ? strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) : '';
        $normalizedMime = $mimeType !== null ? strtolower(trim($mimeType)) : '';

        try {
            if ($normalizedMime === 'application/pdf' || $extension === 'pdf') {
                return $this->extractPdfText($filePath);
            }

            if (
                $normalizedMime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                || $extension === 'docx'
            ) {
                return $this->extractDocxText($filePath);
            }

            if ($normalizedMime === 'text/html' || in_array($extension, ['html', 'htm'], true)) {
                return $this->extractHtmlText($filePath);
            }

            if ($this->isTextOrCode($normalizedMime, $extension)) {
                return $this->extractPlainText($filePath);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to extract text from attachment "{path}": {error}', [
                'path' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Chunks extracted text into overlapping segments suitable for vector embedding.
     *
     * @return string[]
     */
    public function chunkText(
        string $text,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $overlap = self::DEFAULT_CHUNK_OVERLAP,
    ): array {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }

        $length = mb_strlen($trimmed, 'UTF-8');
        if ($length <= $chunkSize) {
            return [$trimmed];
        }

        $chunks = [];
        $start = 0;
        $step = max(100, $chunkSize - $overlap);

        while ($start < $length) {
            $chunk = mb_substr($trimmed, $start, $chunkSize, 'UTF-8');
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $start += $step;
        }

        return $chunks;
    }

    private function extractPdfText(string $filePath): ?string
    {
        $stream = $this->fileUploadService->readStream($filePath);
        $content = stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($content === false || $content === '') {
            return null;
        }

        $parser = $this->pdfParser ?? new PdfParser();
        $pdf = $parser->parseContent($content);
        $text = $pdf->getText();

        return $this->cleanExtractedText($text);
    }

    private function extractDocxText(string $filePath): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }

        $stream = $this->fileUploadService->readStream($filePath);
        $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
        if ($tempFile === false) {
            return null;
        }

        try {
            file_put_contents($tempFile, stream_get_contents($stream));
            if (is_resource($stream)) {
                fclose($stream);
            }

            $zip = new \ZipArchive();
            if ($zip->open($tempFile) === true) {
                $xmlContent = $zip->getFromName('word/document.xml');
                $zip->close();

                if ($xmlContent !== false && $xmlContent !== '') {
                    return $this->cleanExtractedText(strip_tags((string) $xmlContent));
                }
            }
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        return null;
    }

    private function extractHtmlText(string $filePath): ?string
    {
        $stream = $this->fileUploadService->readStream($filePath);
        $content = stream_get_contents($stream, self::MAX_EXTRACTED_CHARS);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($content === false || $content === '') {
            return null;
        }

        return $this->cleanExtractedText(strip_tags((string) $content));
    }

    private function extractPlainText(string $filePath): ?string
    {
        $stream = $this->fileUploadService->readStream($filePath);
        $content = stream_get_contents($stream, self::MAX_EXTRACTED_CHARS);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($content === false || $content === '') {
            return null;
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1, ASCII, Windows-1252');
        }

        return $this->cleanExtractedText((string) $content);
    }

    private function cleanExtractedText(string $text): ?string
    {
        // Remove non-printable control characters except newline and tab
        $cleaned = preg_replace('/[^\P{C}\n\t]/u', '', $text);
        // Normalize multiple horizontal whitespaces
        $cleaned = preg_replace('/[ \t]+/', ' ', (string) $cleaned);
        // Normalize multiple empty lines
        $cleaned = preg_replace('/\n{3,}/', "\n\n", (string) $cleaned);
        $cleaned = trim((string) $cleaned);

        if ($cleaned === '') {
            return null;
        }

        if (mb_strlen($cleaned, 'UTF-8') > self::MAX_EXTRACTED_CHARS) {
            $cleaned = mb_substr($cleaned, 0, self::MAX_EXTRACTED_CHARS, 'UTF-8');
        }

        return $cleaned;
    }

    private function isSupportedMimeType(string $mimeType): bool
    {
        $mime = strtolower(trim($mimeType));
        if ($mime === 'application/pdf') {
            return true;
        }
        if ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return true;
        }
        if (str_starts_with($mime, 'text/')) {
            return true;
        }
        if (in_array(
            $mime,
            [
                'application/json',
                'application/xml',
                'application/x-yaml',
                'application/x-sh',
                'application/sql',
                'application/javascript',
                'application/x-php',
                'application/x-python',
                'application/x-latex',
                'application/rtf',
            ],
            true,
        )) {
            return true;
        }

        return false;
    }

    private function isSupportedExtension(string $extension): bool
    {
        $ext = strtolower(trim($extension));

        return in_array(
            $ext,
            [
                'pdf',
                'docx',
                'txt',
                'md',
                'json',
                'html',
                'htm',
                'xml',
                'yaml',
                'yml',
                'csv',
                'sql',
                'php',
                'js',
                'ts',
                'tsx',
                'py',
                'go',
                'rs',
                'sh',
                'c',
                'cpp',
                'cs',
                'java',
                'css',
                'rtf',
                'tex',
                'log',
                'ini',
                'env',
                'conf',
            ],
            true,
        );
    }

    private function isTextOrCode(string $mimeType, string $extension): bool
    {
        if (str_starts_with($mimeType, 'text/')) {
            return true;
        }

        if (in_array(
            $mimeType,
            [
                'application/json',
                'application/xml',
                'application/x-yaml',
                'application/x-sh',
                'application/sql',
                'application/javascript',
                'application/x-php',
                'application/x-python',
            ],
            true,
        )) {
            return true;
        }

        return in_array(
            $extension,
            [
                'txt',
                'md',
                'json',
                'xml',
                'yaml',
                'yml',
                'csv',
                'sql',
                'php',
                'js',
                'ts',
                'tsx',
                'py',
                'go',
                'rs',
                'sh',
                'c',
                'cpp',
                'cs',
                'java',
                'css',
                'rtf',
                'tex',
                'log',
                'ini',
                'env',
                'conf',
            ],
            true,
        );
    }
}
