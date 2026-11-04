<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use App\Enum\Gender;
use App\Factory\CityFactory;
use App\Factory\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserTest extends EntityTestCase
{
    public function testValidUserHasNoViolations(): void
    {
        self::assertSame([], $this->violations($this->user()));
    }

    public function testEveryUserHasRoleUser(): void
    {
        self::assertSame(['ROLE_USER'], $this->user()->getRoles());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->user()->setRoles(['ROLE_ADMIN'])->getRoles());
    }

    public function testRoleUserIsNotDuplicated(): void
    {
        self::assertSame(['ROLE_USER'], $this->user()->setRoles(['ROLE_USER'])->getRoles());
    }

    public function testFactoryHashesThePassword(): void
    {
        $user = UserFactory::createOne();

        self::assertNotSame(UserFactory::DEFAULT_PASSWORD, $user->getPassword());
        self::assertTrue(
            self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, UserFactory::DEFAULT_PASSWORD),
        );
    }

    public function testSerializedUserDoesNotContainPasswordHash(): void
    {
        $user = UserFactory::createOne();

        self::assertStringNotContainsString((string) $user->getPassword(), serialize($user));
    }

    /**
     * @return iterable<string, array{string, callable(User): User}>
     */
    public static function invalidUsers(): iterable
    {
        yield 'blank email' => ['email', static fn (User $u) => $u->setEmail('')];
        yield 'invalid email' => ['email', static fn (User $u) => $u->setEmail('not-an-email')];
        yield 'blank firstname' => ['firstname', static fn (User $u) => $u->setFirstname('')];
        yield 'too long firstname' => ['firstname', static fn (User $u) => $u->setFirstname(str_repeat('a', 51))];
        yield 'blank lastname' => ['lastname', static fn (User $u) => $u->setLastname('')];
        yield 'too long lastname' => ['lastname', static fn (User $u) => $u->setLastname(str_repeat('a', 51))];
        yield 'birthdate today' => ['birthdate', static fn (User $u) => $u->setBirthdate(new \DateTimeImmutable('today'))];
        yield 'birthdate in future' => ['birthdate', static fn (User $u) => $u->setBirthdate(new \DateTimeImmutable('+1 year'))];
        yield 'unknown nationality' => ['nationality', static fn (User $u) => $u->setNationality('XX')];
    }

    /**
     * @param callable(User): User $breaker
     */
    #[DataProvider('invalidUsers')]
    public function testInvalidUserIsRejected(string $property, callable $breaker): void
    {
        self::assertArrayHasKey($property, $this->violations($breaker($this->user())));
    }

    public function testMissingGenderAndBirthdateAreRejected(): void
    {
        $user = (new User())->setEmail('jane@example.com')->setFirstname('Jane')->setLastname('Doe');

        $violations = $this->violations($user);

        self::assertArrayHasKey('gender', $violations);
        self::assertArrayHasKey('birthdate', $violations);
    }

    public function testDuplicateEmailIsRejectedByValidation(): void
    {
        UserFactory::createOne(['email' => 'jane@example.com']);

        self::assertArrayHasKey('email', $this->violations($this->user()));
    }

    public function testEmailIsTrimmedAndLowercased(): void
    {
        self::assertSame('élodie@example.com', (new User())->setEmail('  ÉLODIE@Example.COM ')->getEmail());
    }

    public function testNullEmailIsAccepted(): void
    {
        // The form calls setEmail(null) on an empty field, before validation: it must not crash
        self::assertNull((new User())->setEmail(null)->getEmail());
    }

    public function testDuplicateEmailWithDifferentCaseIsRejectedByValidation(): void
    {
        UserFactory::createOne(['email' => 'jane@example.com']);

        self::assertArrayHasKey('email', $this->violations($this->user()->setEmail('Jane@Example.com')));
    }

    public function testDuplicateEmailIsRejectedByDatabase(): void
    {
        UserFactory::createOne(['email' => 'jane@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        UserFactory::createOne(['email' => 'jane@example.com']);
    }

    public function testDeletingFavoriteCityKeepsTheUser(): void
    {
        $city = CityFactory::createOne();
        $user = UserFactory::createOne(['favoriteCity' => $city]);
        $id = $user->getId();

        $em = $this->entityManager();
        $em->remove($city);
        $em->flush();
        $em->clear();

        $user = UserFactory::repository()->find($id);
        self::assertNotNull($user);
        self::assertNull($user->getFavoriteCity());
    }

    private function user(): User
    {
        return (new User())
            ->setEmail('jane@example.com')
            ->setPassword('hashed')
            ->setFirstname('Jane')
            ->setLastname('Doe')
            ->setGender(Gender::Female)
            ->setBirthdate(new \DateTimeImmutable('1990-05-12'))
            ->setNationality('FR');
    }
}
