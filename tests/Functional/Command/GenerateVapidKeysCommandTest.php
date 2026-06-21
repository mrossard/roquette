<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateVapidKeysCommandTest extends KernelTestCase
{
    private ?string $backupContent = null;
    private string $envLocalPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->envLocalPath = self::$kernel->getProjectDir() . '/.env.local';
        if (file_exists($this->envLocalPath)) {
            $this->backupContent = file_get_contents($this->envLocalPath);
            unlink($this->envLocalPath);
        }
    }

    protected function tearDown(): void
    {
        if ($this->backupContent !== null) {
            file_put_contents($this->envLocalPath, $this->backupContent);
            parent::tearDown();
            return;
        }

        if (file_exists($this->envLocalPath)) {
            unlink($this->envLocalPath);
        }

        parent::tearDown();
    }

    public function testGenerateKeysAndWrite(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:vapid:generate-keys');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--write' => true,
        ]);

        $output = $commandTester->getDisplay();
        static::assertStringContainsString('Clés VAPID générées', $output);
        static::assertStringContainsString('Clés VAPID écrites dans .env.local', $output);

        static::assertFileExists($this->envLocalPath);
        $content = file_get_contents($this->envLocalPath);
        static::assertStringContainsString('VAPID_PUBLIC_KEY=', $content);
        static::assertStringContainsString('VAPID_PRIVATE_KEY=', $content);
        static::assertStringContainsString('VAPID_SUBJECT=mailto:admin@roquette.local', $content);
    }

    public function testSkipExistingKeys(): void
    {
        // Write existing VAPID keys to .env.local
        file_put_contents($this->envLocalPath, "VAPID_PUBLIC_KEY=dummy_pub\nVAPID_PRIVATE_KEY=dummy_priv\n");

        $application = new Application(self::$kernel);
        $command = $application->find('app:vapid:generate-keys');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            '--skip-existing' => true,
        ]);

        $output = $commandTester->getDisplay();
        static::assertStringContainsString('Les clés VAPID existent déjà. Génération ignorée.', $output);

        $content = file_get_contents($this->envLocalPath);
        static::assertStringContainsString('VAPID_PUBLIC_KEY=dummy_pub', $content);
        static::assertStringContainsString('VAPID_PRIVATE_KEY=dummy_priv', $content);
    }
}
