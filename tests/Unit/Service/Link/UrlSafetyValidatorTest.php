<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Link;

use App\Service\Link\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class UrlSafetyValidatorTest extends TestCase
{
    private UrlSafetyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UrlSafetyValidator();
    }

    #[Test]
    public function isSafeUrlRejectsInvalidUrl(): void
    {
        $this->assertFalse($this->validator->isSafeUrl('not-a-url'));
        $this->assertFalse($this->validator->isSafeUrl('ftp://example.com'));
    }

    #[Test]
    public function isSafeUrlRejectsPrivateIps(): void
    {
        $this->assertFalse($this->validator->isSafeUrl('http://127.0.0.1/test'));
        $this->assertFalse($this->validator->isSafeUrl('http://10.0.0.1/test'));
        $this->assertFalse($this->validator->isSafeUrl('http://192.168.1.1/test'));
        $this->assertFalse($this->validator->isSafeUrl('http://172.16.0.1/test'));
        $this->assertFalse($this->validator->isSafeUrl('http://[::1]/test'));
    }

    #[Test]
    public function isSafeUrlRejectsExtraBlockedRanges(): void
    {
        $this->assertFalse($this->validator->isSafeUrl('http://100.64.0.1/test'));
        $this->assertFalse($this->validator->isSafeUrl('http://0.0.0.0/test'));
    }

    #[Test]
    public function resolveUrlHandlesAbsoluteAndRelative(): void
    {
        $this->assertSame(
            'https://other.com/page',
            $this->validator->resolveUrl('https://example.com/dir/index.html', 'https://other.com/page'),
        );

        $this->assertSame(
            'https://example.com/dir/page.html',
            $this->validator->resolveUrl('https://example.com/dir/index.html', 'page.html'),
        );

        $this->assertSame(
            'https://example.com/root.html',
            $this->validator->resolveUrl('https://example.com/dir/index.html', '/root.html'),
        );

        $this->assertSame(
            'https://example.com/proto.html',
            $this->validator->resolveUrl('https://example.com/dir/index.html', '//example.com/proto.html'),
        );
    }
}
