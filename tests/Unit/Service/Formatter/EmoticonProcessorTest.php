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

        self::assertSame('Bonjour 🙂 !', $processor->process('Bonjour :) !'));
        self::assertSame('Génial 😀', $processor->process('Génial :D'));
        self::assertSame('Clin d\'œil 😉', $processor->process('Clin d\'œil ;)'));
        self::assertSame('Triste 🙁', $processor->process('Triste :('));
        self::assertSame('Langue 😛', $processor->process('Langue :P'));
        self::assertSame('Cool 😎', $processor->process('Cool 8)'));
        self::assertSame('Bisou 😘', $processor->process('Bisou :*'));
        self::assertSame('Rire 😆', $processor->process('Rire xD'));
        self::assertSame('Pleur 😢', $processor->process('Pleur ;('));
        self::assertSame('Amour ❤️', $processor->process('Amour <3'));
    }

    public function testProcessPreservesTextWithoutEmoticons(): void
    {
        $processor = new EmoticonProcessor();

        self::assertSame('Texte simple sans émoticône', $processor->process('Texte simple sans émoticône'));
    }
}
