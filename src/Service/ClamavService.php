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
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$socket) {
            $this->logger->error(sprintf(
                'Failed to connect to ClamAV daemon at %s:%d: %s (%d)',
                $this->host,
                $this->port,
                $errstr,
                $errno,
            ));
            throw new \RuntimeException('Le service d\'analyse antivirus est temporairement indisponible.');
        }

        // Send INSTREAM command (nINSTREAM\n is standard for TCP)
        fwrite($socket, "nINSTREAM\n");

        $handle = fopen($file->getPathname(), 'rb');
        if (!$handle) {
            fclose($socket);
            throw new \RuntimeException('Impossible d\'ouvrir le fichier pour l\'analyse antivirus.');
        }

        // Stream the file in chunks
        $chunkSize = 8192;
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                break;
            }
            $length = strlen($chunk);
            if ($length > 0) {
                fwrite($socket, pack('N', $length));
                fwrite($socket, $chunk);
            }
        }
        fclose($handle);

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
                $file->getClientOriginalName(),
                $response,
            ));
            return false;
        }

        $this->logger->error(sprintf(
            'ClamAV returned an unexpected response for "%s": %s',
            $file->getClientOriginalName(),
            $response,
        ));
        throw new \RuntimeException('Une erreur est survenue lors de l\'analyse antivirus du fichier.');
    }
}
