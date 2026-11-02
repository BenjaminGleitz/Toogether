<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

#[\Attribute]
final class PasswordRequirements extends Compound
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<Assert\Sequentially>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Sequentially([
                new Assert\NotBlank(message: 'user.password.not_blank'),
                new Assert\Length(
                    min: 8,
                    max: 4096,
                    minMessage: 'user.password.length',
                ),
                new Assert\NotCompromisedPassword(),
            ]),
        ];
    }
}