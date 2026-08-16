<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Formatter;

use App\Service\Formatter\EmoticonProcessor;
use PHPUnit\Framework\TestCase;

class EmoticonProcessorTest extends TestCase
{
    public function testProcessConvertsAsciiEmoticons(): void
    {
        $processor = new EmoticonProcessor();

        static::assertSame('Bonjour 🙂 !', $processor->process('Bonjour :) !'));
        static::assertSame('Génial 😀', $processor->process('Génial :D'));
        static::assertSame('Clin d\'œil 😉', $processor->process('Clin d\'œil ;)'));
        static::assertSame('Triste 🙁', $processor->process('Triste :('));
        static::assertSame('Langue 😛', $processor->process('Langue :P'));
        static::assertSame('Cool 😎', $processor->process('Cool 8)'));
        static::assertSame('Bisou 😘', $processor->process('Bisou :*'));
        static::assertSame('Rire 😆', $processor->process('Rire xD'));
        static::assertSame('Pleur 😢', $processor->process('Pleur ;('));
        static::assertSame('Amour ❤️', $processor->process('Amour <3'));
    }

    public function testProcessPreservesTextWithoutEmoticons(): void
    {
        $processor = new EmoticonProcessor();

        static::assertSame('Texte simple sans émoticône', $processor->process('Texte simple sans émoticône'));
    }
}
