<?php

declare(strict_types=1);

namespace App\Service\Export;

use PharData;
use RuntimeException;

/**
 * Creates TAR format export archives using PharData.
 */
class TarArchiveExporter implements ArchiveExporterInterface
{
    public function isSupported(): bool
    {
        return class_exists(PharData::class);
    }

    public function getExtension(): string
    {
        return 'tar';
    }

    public function createArchive(array $stringEntries, array $streamEntries): string
    {
        if (!$this->isSupported()) {
            throw new RuntimeException('PharData is not available.');
        }

        $tarFile = tempnam(sys_get_temp_dir(), 'export-') . '.tar';
        $tar = new PharData($tarFile);

        foreach ($stringEntries as $entryPath => $content) {
            $tar->addFromString($entryPath, $content);
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

                $tar->addFile($tmpFile, $entryPath);
                $tmpFiles[] = $tmpFile;
            }
        } finally {
            unset($tar);
        }

        foreach ($tmpFiles as $tmpFile) {
            if (!file_exists($tmpFile)) {
                continue;
            }

            unlink($tmpFile);
        }

        return $tarFile;
    }
}
