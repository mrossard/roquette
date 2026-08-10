<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[AllowMockObjectsWithoutExpectations]
class SecurityHeadersFunctionalTest extends WebTestCase
{
    #[Test]
    public function testSecurityHeadersPresentOnLoginPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        $this->assertResponseHeaderSame('X-Frame-Options', 'DENY');
        $this->assertResponseHeaderSame('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertResponseHasHeader('Content-Security-Policy');
    }
}
