<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\PasswordRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PasswordRequirementsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function passwords(): iterable
    {
        yield 'null' => [null, ['Veuillez saisir un mot de passe.']];
        yield 'empty' => ['', ['Veuillez saisir un mot de passe.']];
        // Sequentially stops at the first failing constraint: a single, clear message
        yield '7 characters' => ['abcdefg', ['Votre mot de passe doit contenir au moins 8 caractères.']];
        yield '8 characters' => ['abcdefgh', []];
        yield 'long password' => [str_repeat('a', 64), []];
        yield 'too long' => [str_repeat('a', 4097), ['Cette chaîne est trop longue. Elle doit contenir au maximum 4096 caractères.']];
    }

    /**
     * @param list<string> $expectedMessages
     */
    #[DataProvider('passwords')]
    public function testPasswordRequirements(?string $password, array $expectedMessages): void
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($password, new PasswordRequirements());

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        self::assertSame($expectedMessages, $messages);
    }
}
