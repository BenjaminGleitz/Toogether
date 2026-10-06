<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

abstract class EntityTestCase extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * Validates the object and returns the violation messages indexed by property path.
     *
     * @return array<string, list<string>>
     */
    protected function violations(object $entity): array
    {
        $violations = [];
        foreach (self::getContainer()->get(ValidatorInterface::class)->validate($entity) as $violation) {
            $violations[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $violations;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
