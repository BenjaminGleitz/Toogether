<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\TimestampableInterface;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\Clock\ClockInterface;

#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: User::class)]
final class TimestampListener
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function prePersist(TimestampableInterface $entity): void
    {
        $entity->setCreatedAt($this->clock->now());
    }

    public function preUpdate(TimestampableInterface $entity): void
    {
        $entity->setUpdatedAt($this->clock->now());
    }
}
