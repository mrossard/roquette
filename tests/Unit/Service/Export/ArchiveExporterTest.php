<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Export;

use App\Service\Export\TarArchiveExporter;
use App\Service\Export\ZipArchiveExporter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ArchiveExporterTest extends TestCase
{
    #[Test]
    public function zipArchiveExporterCreatesValidZipWithEntries(): void
    {
        $exporter = new ZipArchiveExporter();
        if (!$exporter->isSupported()) {
            static::markTestSkipped('ZipArchive not supported on this platform');
        }

        static::assertSame('zip', $exporter->getExtension());

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'Binary file content');
        rewind($stream);

        $archivePath = $exporter->createArchive(
            stringEntries: ['channel.json' => '{"name": "test"}', 'channel.html' => '<html>Test</html>'],
            streamEntries: ['files/test.txt' => $stream],
        );

        fclose($stream);

        static::assertFileExists($archivePath);
        static::assertGreaterThan(0, filesize($archivePath));

        $zip = new \ZipArchive();
        static::assertTrue($zip->open($archivePath));
        static::assertSame('{"name": "test"}', $zip->getFromName('channel.json'));
        static::assertSame('<html>Test</html>', $zip->getFromName('channel.html'));
        static::assertSame('Binary file content', $zip->getFromName('files/test.txt'));
        $zip->close();

        unlink($archivePath);
    }

    #[Test]
    public function tarArchiveExporterCreatesValidTarWithEntries(): void
    {
        $exporter = new TarArchiveExporter();
        if (!$exporter->isSupported()) {
            static::markTestSkipped('PharData not supported on this platform');
        }

        static::assertSame('tar', $exporter->getExtension());

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'Tar binary content');
        rewind($stream);

        $archivePath = $exporter->createArchive(
            stringEntries: ['channel.json' => '{"name": "tar_test"}'],
            streamEntries: ['files/attachment.txt' => $stream],
        );

        fclose($stream);

        static::assertFileExists($archivePath);
        static::assertGreaterThan(0, filesize($archivePath));

        $phar = new \PharData($archivePath);
        static::assertTrue($phar->offsetExists('channel.json'));
        static::assertSame('{"name": "tar_test"}', $phar['channel.json']->getContent());
        static::assertTrue($phar->offsetExists('files/attachment.txt'));
        static::assertSame('Tar binary content', $phar['files/attachment.txt']->getContent());
        unset($phar);

        unlink($archivePath);
    }
}
