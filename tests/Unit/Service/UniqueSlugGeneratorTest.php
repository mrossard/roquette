<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\UniqueSlugGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

class UniqueSlugGeneratorTest extends TestCase
{
    private UniqueSlugGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new UniqueSlugGenerator(new AsciiSlugger());
    }

    #[Test]
    public function generateReturnsDirectSlugWhenNotTaken(): void
    {
        $slug = $this->generator->generate('Mon Super Canal', 'channel', static fn(string $s) => false);
        $this->assertSame('mon-super-canal', $slug);
    }

    #[Test]
    public function generateAppendsSuffixWhenSlugIsTaken(): void
    {
        $existing = ['mon-canal'];
        $slug = $this->generator->generate('Mon Canal', 'channel', static fn(string $s) => in_array(
            $s,
            $existing,
            true,
        ));

        $this->assertNotSame('mon-canal', $slug);
        $this->assertStringStartsWith('mon-canal-', $slug);
    }

    #[Test]
    public function generateUsesFallbackPrefixWhenNameIsEmpty(): void
    {
        $slug = $this->generator->generate('???', 'fallback', static fn(string $s) => false);
        $this->assertStringStartsWith('fallback-', $slug);
    }
}
