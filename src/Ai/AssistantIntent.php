<?php

declare(strict_types=1);

namespace App\Ai;

enum AssistantIntent: string
{
    case Help = 'help';
    case Summarize = 'resumer';
    case Poll = 'sondage';
}
