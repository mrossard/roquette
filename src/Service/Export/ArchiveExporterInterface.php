<?php

declare(strict_types=1);

namespace App\Service\Export;

interface ArchiveExporterInterface
{
    /**
     * Returns true if the environment supports this archive exporter.
     */
    public function isSupported(): bool;

    /**
     * Returns the file extension for this archive format (e.g. 'zip', 'tar').
     */
    public function getExtension(): string;

    /**
     * Creates an archive file containing the specified string entries and stream entries.
     *
     * @param array<string, string> $stringEntries relative path => string content
     * @param array<string, resource> $streamEntries relative path => readable resource stream
     *
     * @return string absolute path of the generated temporary archive file
     *
     * @throws \RuntimeException if archive creation fails
     */
    public function createArchive(array $stringEntries, array $streamEntries): string;
}
