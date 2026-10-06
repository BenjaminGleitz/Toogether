<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\City;
use App\Entity\Country;
use App\Factory\CityFactory;
use App\Factory\CountryFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CityTest extends EntityTestCase
{
    public function testValidCityHasNoViolations(): void
    {
        $city = $this->city('Lyon', CountryFactory::createOne());

        self::assertSame([], $this->violations($city));
    }

    public function testOneLetterCityNameIsAllowed(): void
    {
        // "Y" is a real commune in the Somme: no min length on City.name
        $city = $this->city('Y', CountryFactory::createOne());

        self::assertSame([], $this->violations($city));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'blank' => [''];
        yield 'too long' => [str_repeat('a', 51)];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRejected(string $name): void
    {
        $city = $this->city($name, CountryFactory::createOne());

        self::assertArrayHasKey('name', $this->violations($city));
    }

    public function testCityWithoutCountryIsRejected(): void
    {
        $violations = $this->violations($this->city('Lyon', null));

        self::assertSame(['Cette valeur ne doit pas être nulle.'], $violations['country']);
    }

    public function testSameNameInSameCountryIsRejectedByValidation(): void
    {
        $france = CountryFactory::createOne();
        CityFactory::createOne(['name' => 'Valence', 'country' => $france]);

        self::assertArrayHasKey('name', $this->violations($this->city('Valence', $france)));
    }

    public function testSameNameInAnotherCountryIsAllowed(): void
    {
        CityFactory::createOne(['name' => 'Valence', 'country' => CountryFactory::createOne()]);

        $city = $this->city('Valence', CountryFactory::createOne());

        self::assertSame([], $this->violations($city));
    }

    public function testSameNameInSameCountryIsRejectedByDatabase(): void
    {
        $france = CountryFactory::createOne();
        CityFactory::createOne(['name' => 'Valence', 'country' => $france]);

        $this->expectException(UniqueConstraintViolationException::class);

        $em = $this->entityManager();
        $em->persist($this->city('Valence', $france));
        $em->flush();
    }

    private function city(string $name, ?Country $country): City
    {
        return (new City())->setName($name)->setCountry($country);
    }
}
