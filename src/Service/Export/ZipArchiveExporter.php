<?php

declare(strict_types=1);

namespace App\Service\Export;

use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use ZipArchive;

/**
 * Creates ZIP format export archives using ZipArchive.
 */
class ZipArchiveExporter implements ArchiveExporterInterface
{
    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function isSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function getExtension(): string
    {
        return 'zip';
    }

    public function createArchive(array $stringEntries, array $streamEntries): string
    {
        if (!$this->isSupported()) {
            throw new RuntimeException('ZipArchive is not available.');
        }

        $zip = new ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'export-');
        $zipFile = $tempFile . '.zip';
        if ($tempFile !== false && file_exists($tempFile)) {
            unlink($tempFile);
        }
        if ($zipFile === false || $zip->open($zipFile, ZipArchive::CREATE) !== true) {
            $msg = $this->translator?->trans('Impossible de créer l\'archive ZIP.') ?? 'Unable to create ZIP archive.';
            throw new RuntimeException($msg);
        }

        foreach ($stringEntries as $entryPath => $content) {
            $zip->addFromString($entryPath, $content);
        }

        $tmpFiles = [];
        try {
            foreach ($streamEntries as $entryPath => $fileStream) {
                if (!is_resource($fileStream)) {
                    continue;
                }

                $tmpFile = tempnam(sys_get_temp_dir(), 'attach-');
                if ($tmpFile === false) {
                    continue;
                }

                $tmpStream = fopen($tmpFile, 'wb');
                if ($tmpStream !== false) {
                    stream_copy_to_stream($fileStream, $tmpStream);
                    fclose($tmpStream);
                }

                if ($zip->addFile($tmpFile, $entryPath) !== true) {
                    throw new RuntimeException('Failed to add attachment to ZIP: ' . $tmpFile);
                }

                $tmpFiles[] = $tmpFile;
            }
        } finally {
            // Keep tmpFiles to clean up after closing the zip
        }

        $status = $zip->getStatusString();
        $closed = $zip->close();
        if (!$closed) {
            throw new RuntimeException('ZipArchive::close() failed. Status: ' . $status);
        }

        // Clean up temporary attachment files
        foreach ($tmpFiles as $tmpFile) {
            if (!file_exists($tmpFile)) {
                continue;
            }

            unlink($tmpFile);
        }

        if (!file_exists($zipFile)) {
            throw new RuntimeException('Zip file does not exist after closing: ' . $zipFile);
        }

        return $zipFile;
    }
}
