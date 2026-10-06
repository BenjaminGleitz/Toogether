<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\Country;
use Symfony\Component\Intl\Countries;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Country>
 */
final class CountryFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Country::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return static function (): array {
            // A real ISO 3166-1 alpha-2 code (satisfies Assert\Country), unique per test run
            $code = self::faker()->unique()->randomElement(Countries::getCountryCodes());

            return [
                'countryCode' => $code,
                'name' => mb_substr(Countries::getName($code, 'fr'), 0, 50),
            ];
        };
    }
}
