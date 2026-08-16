<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

class UniqueSlugGenerator
{
    public function __construct(
        private readonly SluggerInterface $slugger,
    ) {}

    /**
     * @param callable(string): bool $existsChecker Returns true if the generated slug already exists.
     */
    public function generate(string $name, string $fallbackPrefix, callable $existsChecker): string
    {
        $slug = strtolower($this->slugger->slug($name)->toString());
        if ($slug === '') {
            $slug = $fallbackPrefix . '-' . uniqid();
        }

        $baseSlug = $slug;
        $count = 1;
        while ($existsChecker($slug)) {
            $slug = $baseSlug . '-' . random_int(100, 999);
            if ($count++ > 20) {
                $slug = $baseSlug . '-' . uniqid();
                break;
            }
        }

        return $slug;
    }
}
