<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Factory\UserFactory;
use App\Tests\Entity\EntityTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TimestampListenerTest extends EntityTestCase
{
    use ClockSensitiveTrait;

    public function testCreatedAtIsSetOnPersist(): void
    {
        self::mockTime('2026-01-15 10:00:00');

        $user = UserFactory::createOne();

        self::assertEquals(new \DateTimeImmutable('2026-01-15 10:00:00'), $user->getCreatedAt());
        self::assertNull($user->getUpdatedAt());
    }

    public function testUpdatedAtIsSetOnUpdateAndCreatedAtIsKept(): void
    {
        $clock = self::mockTime('2026-01-15 10:00:00');
        $user = UserFactory::createOne();

        $clock->sleep(3600);
        $user->setFirstname('Changed');
        $this->entityManager()->flush();

        self::assertEquals(new \DateTimeImmutable('2026-01-15 10:00:00'), $user->getCreatedAt());
        self::assertEquals(new \DateTimeImmutable('2026-01-15 11:00:00'), $user->getUpdatedAt());
    }

    public function testFlushWithoutChangeDoesNotSetUpdatedAt(): void
    {
        $clock = self::mockTime('2026-01-15 10:00:00');
        $user = UserFactory::createOne();

        $clock->sleep(3600);
        $this->entityManager()->flush();

        self::assertNull($user->getUpdatedAt());
    }

    public function testTimestampsArePersistedInDatabase(): void
    {
        self::mockTime('2026-01-15 10:00:00');
        $id = UserFactory::createOne()->getId();

        $this->entityManager()->clear();
        $user = UserFactory::repository()->find($id);

        self::assertNotNull($user);
        self::assertEquals(new \DateTimeImmutable('2026-01-15 10:00:00'), $user->getCreatedAt());
    }
}
