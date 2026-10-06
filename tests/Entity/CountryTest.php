<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Factory\CityFactory;
use App\Factory\CountryFactory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CountryTest extends EntityTestCase
{
    public function testValidCountryHasNoViolations(): void
    {
        self::assertSame([], $this->violations($this->country('France', 'FR')));
    }

    public function testErrorMessagesAreInFrench(): void
    {
        $violations = $this->violations($this->country('', 'FR'));

        self::assertContains('Cette valeur ne doit pas être vide.', $violations['name']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'blank' => [''];
        yield 'too short' => ['F'];
        yield 'too long' => [str_repeat('a', 51)];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRejected(string $name): void
    {
        self::assertArrayHasKey('name', $this->violations($this->country($name, 'FR')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCountryCodes(): iterable
    {
        yield 'blank' => [''];
        yield 'unknown code' => ['XX'];
        yield 'full name instead of code' => ['France'];
        yield 'alpha-3 code' => ['FRA'];
    }

    #[DataProvider('invalidCountryCodes')]
    public function testInvalidCountryCodeIsRejected(string $code): void
    {
        self::assertArrayHasKey('countryCode', $this->violations($this->country('France', $code)));
    }

    public function testDuplicateCountryCodeIsRejectedByValidation(): void
    {
        CountryFactory::createOne(['countryCode' => 'FR']);

        self::assertArrayHasKey('countryCode', $this->violations($this->country('France bis', 'FR')));
    }

    public function testDuplicateCountryCodeIsRejectedByDatabase(): void
    {
        CountryFactory::createOne(['countryCode' => 'FR']);

        $this->expectException(UniqueConstraintViolationException::class);

        $em = $this->entityManager();
        $em->persist($this->country('France bis', 'FR'));
        $em->flush();
    }

    public function testCountryWithCitiesCannotBeDeleted(): void
    {
        $country = CountryFactory::createOne();
        CityFactory::createOne(['country' => $country]);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $em = $this->entityManager();
        $em->remove($country);
        $em->flush();
    }

    public function testAddCityKeepsBothSidesInSync(): void
    {
        $country = $this->country('France', 'FR');
        $city = CityFactory::new()->withoutPersisting()->create(['country' => null]);

        $country->addCity($city);

        self::assertSame($country, $city->getCountry());
        self::assertTrue($country->getCities()->contains($city));
    }

    private function country(string $name, string $code): Country
    {
        return (new Country())->setName($name)->setCountryCode($code);
    }
}
