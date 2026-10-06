<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Factory\CategoryFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CategoryTest extends EntityTestCase
{
    public function testValidCategoryHasNoViolations(): void
    {
        self::assertSame([], $this->violations($this->category('Sport')));
    }

    public function testImageIsOptional(): void
    {
        $category = $this->category('Sport')->setImage(null);

        self::assertSame([], $this->violations($category));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTitles(): iterable
    {
        yield 'blank' => [''];
        yield 'too short' => ['S'];
        yield 'too long' => [str_repeat('a', 51)];
    }

    #[DataProvider('invalidTitles')]
    public function testInvalidTitleIsRejected(string $title): void
    {
        self::assertArrayHasKey('title', $this->violations($this->category($title)));
    }

    public function testDuplicateTitleIsRejectedByValidation(): void
    {
        CategoryFactory::createOne(['title' => 'Sport']);

        self::assertSame(
            ['Cette valeur est déjà utilisée.'],
            $this->violations($this->category('Sport'))['title'] ?? [],
        );
    }

    public function testDuplicateTitleIsRejectedByDatabase(): void
    {
        CategoryFactory::createOne(['title' => 'Sport']);

        $this->expectException(UniqueConstraintViolationException::class);

        $em = $this->entityManager();
        $em->persist($this->category('Sport'));
        $em->flush();
    }

    private function category(string $title): Category
    {
        return (new Category())->setTitle($title);
    }
}
