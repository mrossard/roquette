<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ClamavService
{
    public function __construct(
        #[Autowire(env: 'CLAMAV_HOST')]
        private string $host,
        #[Autowire(env: 'int:CLAMAV_PORT')]
        private int $port,
        private LoggerInterface $logger,
    ) {}

    /**
     * Scans a file. Returns true if clean, false if a virus is found.
     * Throws an exception on connection/communication errors.
     */
    public function scanFile(UploadedFile $file): bool
    {
        $handle = fopen($file->getPathname(), 'rb');
        if (!$handle) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier pour l\'analyse antivirus.');
        }
        try {
            return $this->scanStream($handle, $file->getClientOriginalName());
        } finally {
            fclose($handle);
        }
    }

    /**
     * Scans a readable stream resource. Returns true if clean, false if a virus is found.
     * Throws an exception on connection/communication errors.
     *
     * @param resource $stream
     */
    public function scanStream(mixed $stream, string $originalFileName): bool
    {
        try {
            set_error_handler(static function ($severity, $message, $file, $line) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            $socket = fsockopen($this->host, $this->port, $errno, $errstr, 5);
        } catch (\ErrorException $e) {
            $this->logger->error(sprintf(
                'Failed to connect to ClamAV daemon at %s:%d: %s (error code: %d, exception: %s)',
                $this->host,
                $this->port,
                $errstr !== null && $errstr !== '' ? $errstr : 'Unknown error',
                $errno,
                $e->getMessage(),
            ));
            throw new \RuntimeException('Le service d\'analyse antivirus est temporairement indisponible.', 0, $e);
        } finally {
            restore_error_handler();
        }

        if (!$socket) {
            $this->logger->error(sprintf(
                'Failed to connect to ClamAV daemon at %s:%d: %s (%d)',
                $this->host,
                $this->port,
                $errstr !== null && $errstr !== '' ? $errstr : 'Unknown error',
                $errno,
            ));
            throw new \RuntimeException('Le service d\'analyse antivirus est temporairement indisponible.');
        }

        // Send INSTREAM command (nINSTREAM\n is standard for TCP)
        fwrite($socket, "nINSTREAM\n");

        // Stream the file in chunks
        $chunkSize = 8192;
        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            if ($chunk === false) {
                break;
            }
            $length = strlen($chunk);
            if ($length > 0) {
                fwrite($socket, pack('N', $length));
                fwrite($socket, $chunk);
            }
        }

        // Terminate stream
        fwrite($socket, pack('N', 0));

        // Read response
        $response = '';
        while (!feof($socket)) {
            $response .= fgets($socket, 128);
        }
        fclose($socket);

        $response = trim($response);

        if (str_contains($response, 'OK')) {
            return true;
        }

        if (str_contains($response, 'FOUND')) {
            $this->logger->warning(sprintf(
                'Virus detected in uploaded file "%s": %s',
                $originalFileName,
                $response,
            ));
            return false;
        }

        $this->logger->error(sprintf(
            'ClamAV returned an unexpected response for "%s": %s',
            $originalFileName,
            $response,
        ));
        throw new \RuntimeException('Une erreur est survenue lors de l\'analyse antivirus du fichier.');
    }
}
