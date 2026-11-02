<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\Gender;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RegistrationControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'jane@example.com';
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testRegisterPageIsDisplayedInFrench(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertPageTitleSame('Créer un compte');
        self::assertSelectorTextSame('h1', 'Créer un compte');
        self::assertSelectorTextSame('label[for="registration_form_email"]', 'Adresse e-mail');
        self::assertSelectorTextSame('label[for="registration_form_firstname"]', 'Prénom');
        self::assertSelectorTextSame('label[for="registration_form_lastname"]', 'Nom');
        self::assertSelectorTextSame('label[for="registration_form_gender_0"]', 'Homme');
        self::assertSelectorTextSame('label[for="registration_form_birthdate"]', 'Date de naissance');
        self::assertSelectorTextSame('label[for="registration_form_plainPassword"]', 'Mot de passe');
        self::assertSelectorTextSame('label[for="registration_form_agreeTerms"]', "J'accepte les conditions d'utilisation");
        self::assertSelectorTextSame('button[type="submit"]', 'Créer un compte');
    }

    public function testSuccessfulRegistrationCreatesUserLogsInAndQueuesConfirmationEmail(): void
    {
        $this->submitRegistration();

        self::assertResponseRedirects('/');

        $user = UserFactory::repository()->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Jane', $user->getFirstname());
        self::assertSame('Doe', $user->getLastname());
        self::assertSame(Gender::Female, $user->getGender());
        self::assertSame('1990-05-17', $user->getBirthdate()?->format('Y-m-d'));
        self::assertFalse($user->isVerified());

        // The password is hashed, never stored in clear
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::PASSWORD));

        self::assertSame(self::EMAIL, $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());

        // Emails go through Messenger (async): the message is queued, not sent
        self::assertQueuedEmailCount(1);
        $email = $this->getConfirmationEmail();
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Confirmez votre adresse e-mail');
        self::assertEmailHtmlBodyContains($email, 'Bonjour Jane');
        self::assertEmailHtmlBodyContains($email, 'Confirmer');
        self::assertEmailHtmlBodyContains($email, 'expire dans 1 heure');
    }

    public function testExpiredConfirmationLinkIsRejected(): void
    {
        $this->submitRegistration();

        // Move the expiry into the past and re-sign the URL the same way the bundle does,
        // so that only the expiry check fails (not the signature check)
        $link = preg_replace('/expires=\d+/', 'expires='.(time() - 60), $this->getConfirmationLink());
        self::assertIsString($link);
        $signer = new UriSigner(self::getContainer()->getParameter('kernel.secret'), 'signature');
        $link = $signer->sign(preg_replace('/[&?]signature=[^&]+/', '', $link) ?? $link);

        $this->client->request('GET', $link);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Le lien pour vérifier votre adresse e-mail a expiré.');
        self::assertFalse(UserFactory::repository()->findOneBy(['email' => self::EMAIL])?->isVerified());
    }

    public function testConfirmationLinkIsRejectedWhenTheEmailChangedInTheMeantime(): void
    {
        $this->submitRegistration();
        $link = $this->getConfirmationLink();

        $user = UserFactory::repository()->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $user->setEmail('other@example.com');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', $link);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'semble correspondre à un autre compte');
        self::assertFalse($user->isVerified());
    }

    public function testCrossOriginSubmissionIsRejectedByCsrfProtection(): void
    {
        $this->client->request('POST', '/register', [
            'registration_form' => [
                '_token' => 'csrf-token',
                'email' => self::EMAIL,
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'gender' => Gender::Female->value,
                'birthdate' => '1990-05-17',
                'plainPassword' => self::PASSWORD,
                'agreeTerms' => '1',
            ],
        ], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(422);
        $this->assertFormHasError('Le jeton CSRF est invalide.');
        self::assertSame(0, UserFactory::repository()->count([]));
    }

    public function testConfirmationLinkVerifiesTheEmail(): void
    {
        $this->submitRegistration();
        $link = $this->getConfirmationLink();

        $this->client->request('GET', $link);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash--success', 'Votre adresse e-mail a bien été vérifiée.');
        self::assertTrue(UserFactory::repository()->findOneBy(['email' => self::EMAIL])?->isVerified());
    }

    public function testTamperedConfirmationLinkIsRejected(): void
    {
        $this->submitRegistration();
        $link = preg_replace('/signature=[^&]+/', 'signature=tampered', $this->getConfirmationLink());
        self::assertIsString($link);

        $this->client->request('GET', $link);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Le lien pour vérifier votre adresse e-mail est invalide.');
        self::assertFalse(UserFactory::repository()->findOneBy(['email' => self::EMAIL])?->isVerified());
    }

    public function testConfirmationLinkWithoutIdRedirectsToRegistration(): void
    {
        $this->client->request('GET', '/verify/email');

        self::assertResponseRedirects('/register');
    }

    public function testConfirmationLinkWithUnknownIdRedirectsToRegistration(): void
    {
        $this->client->request('GET', '/verify/email?id=999999');

        self::assertResponseRedirects('/register');
    }

    /**
     * @param array<string, string|bool|null> $overrides form values to change (null = leave the field untouched)
     */
    #[DataProvider('invalidSubmissions')]
    public function testInvalidSubmissionIsRejected(array $overrides, string $expectedError): void
    {
        $this->submitRegistration($overrides);

        self::assertResponseStatusCodeSame(422);
        $this->assertFormHasError($expectedError);
        self::assertSame(0, UserFactory::repository()->count([]));
        self::assertQueuedEmailCount(0);
    }

    /**
     * @return iterable<string, array{array<string, string|bool|null>, string}>
     */
    public static function invalidSubmissions(): iterable
    {
        yield 'empty email' => [['email' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'invalid email' => [['email' => 'not-an-email'], "Cette valeur n'est pas une adresse e-mail valide."];
        yield 'empty firstname' => [['firstname' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'firstname too long' => [['firstname' => str_repeat('a', 51)], 'Cette chaîne est trop longue.'];
        yield 'empty lastname' => [['lastname' => ''], 'Cette valeur ne doit pas être vide.'];
        yield 'lastname too long' => [['lastname' => str_repeat('a', 51)], 'Cette chaîne est trop longue.'];
        yield 'no gender' => [['gender' => null], 'Cette valeur ne doit pas être nulle.'];
        // Regression: an empty birthdate used to crash with a 500 (non-nullable setter)
        yield 'empty birthdate' => [['birthdate' => ''], 'Cette valeur ne doit pas être nulle.'];
        yield 'birthdate in the future' => [['birthdate' => '2999-01-01'], 'Cette valeur doit être inférieure à'];
        yield 'empty password' => [['plainPassword' => ''], 'Veuillez saisir un mot de passe.'];
        yield 'short password' => [['plainPassword' => 'abc'], 'Votre mot de passe doit contenir au moins 6 caractères.'];
        yield 'terms not accepted' => [['agreeTerms' => false], "Vous devez accepter les conditions d'utilisation."];
    }

    public function testEmailAlreadyUsedIsRejected(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->submitRegistration();

        self::assertResponseStatusCodeSame(422);
        $this->assertFormHasError('Cette valeur est déjà utilisée.');
        self::assertSame(1, UserFactory::repository()->count([]));
    }

    public function testLoggedInUserIsRedirectedAwayFromRegisterPage(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/register');

        self::assertResponseRedirects('/');
    }

    public function testLoggedInUserCannotSubmitRegistration(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        // Form displayed before logging in (e.g. in another tab), submitted once logged in
        $this->client->request('POST', '/register', [
            'registration_form' => [
                'email' => 'other@example.com',
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'gender' => Gender::Female->value,
                'birthdate' => '1990-05-17',
                'plainPassword' => self::PASSWORD,
                'agreeTerms' => '1',
            ],
        ]);

        self::assertResponseRedirects('/');
        self::assertSame(1, UserFactory::repository()->count([]));
        self::assertQueuedEmailCount(0);
    }

    public function testEmailIsStoredInLowercase(): void
    {
        $this->submitRegistration(['email' => 'Jane@Example.COM']);

        self::assertResponseRedirects('/');
        self::assertSame(1, UserFactory::repository()->count(['email' => self::EMAIL]));
    }

    public function testEmailAlreadyUsedWithDifferentCaseIsRejected(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->submitRegistration(['email' => 'JANE@example.com']);

        self::assertResponseStatusCodeSame(422);
        $this->assertFormHasError('Cette valeur est déjà utilisée.');
        self::assertSame(1, UserFactory::repository()->count([]));
    }

    /**
     * @param array<string, string|bool|null> $overrides
     */
    private function submitRegistration(array $overrides = []): void
    {
        $values = array_merge([
            'email' => self::EMAIL,
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'gender' => Gender::Female->value,
            'birthdate' => '1990-05-17',
            'plainPassword' => self::PASSWORD,
            'agreeTerms' => true,
        ], $overrides);

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Créer un compte')->form();

        foreach ($values as $field => $value) {
            if (null === $value) {
                continue;
            }
            $form['registration_form['.$field.']']->setValue($value);
        }

        $this->client->submit($form);
    }

    /**
     * Some messages end with a dynamic part (e.g. the compared date): match on the start of the message.
     */
    private function assertFormHasError(string $expectedStart): void
    {
        // Field errors are inside the <form>, global ones (e.g. CSRF) are rendered just above it
        $errors = $this->client->getCrawler()->filter('ul li')->each(
            static fn (Crawler $li): string => $li->text(),
        );

        foreach ($errors as $error) {
            if (str_starts_with($error, $expectedStart)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        self::fail(\sprintf('No form error starting with "%s". Errors: %s', $expectedStart, json_encode($errors, \JSON_UNESCAPED_UNICODE)));
    }

    private function getConfirmationEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function getConfirmationLink(): string
    {
        $html = (string) $this->getConfirmationEmail()->getHtmlBody();
        $link = (new Crawler($html))->filter('a')->attr('href');
        self::assertIsString($link);

        return $link;
    }
}
