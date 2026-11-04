<?php

declare(strict_types=1);

namespace App\Factory;

use App\Entity\User;
use App\Enum\Gender;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'password';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    #[\Override]
    public static function class(): string
    {
        return User::class;
    }

    public function admin(): static
    {
        return $this->with(['roles' => ['ROLE_ADMIN']]);
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'password' => self::DEFAULT_PASSWORD,
            'firstname' => mb_substr(self::faker()->firstName(), 0, 50),
            'lastname' => mb_substr(self::faker()->lastName(), 0, 50),
            'gender' => self::faker()->randomElement(Gender::cases()),
            'birthdate' => \DateTimeImmutable::createFromMutable(self::faker()->dateTimeBetween('-70 years', '-18 years')),
        ];
    }

    #[\Override]
    protected function initialize(): static
    {
        // The "password" attribute is the plain password: hash it like the registration form will
        return $this->afterInstantiate(function (User $user): void {
            $user->setPassword($this->passwordHasher->hashPassword($user, (string) $user->getPassword()));
        });
    }
}
