<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Category;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Category>
 */
final class CategoryFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Category::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'title' => ucfirst(self::faker()->unique()->words(2, true)),
            'image' => null,
        ];
    }
}
