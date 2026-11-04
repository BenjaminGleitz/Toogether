<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ResetPasswordControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'jane@example.com';
    private const string NEW_PASSWORD = 'new-secret-123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Login throttling state lives in a filesystem cache pool that survives between tests and runs:
        // without this, failed logins from previous runs end up blocking the test client's IP.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testRequestPageIsDisplayedInFrench(): void
    {
        $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertPageTitleSame('Réinitialiser votre mot de passe');
        self::assertSelectorTextSame('h1', 'Réinitialiser votre mot de passe');
        self::assertSelectorTextSame('label[for="reset_password_request_form_email"]', 'Adresse e-mail');
        self::assertSelectorTextSame('button', 'Envoyer le lien');
    }

    public function testRequestForExistingAccountQueuesResetEmail(): void
    {
        UserFactory::createOne(['email' => self::EMAIL, 'firstname' => 'Jane']);

        $this->submitRequest(self::EMAIL);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(1);
        $email = $this->getResetEmail();
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Réinitialisation de votre mot de passe');
        self::assertEmailHtmlBodyContains($email, 'Pour réinitialiser votre mot de passe');
        self::assertEmailHtmlBodyContains($email, 'Ce lien expire dans 1 heure.');
        self::assertStringContainsString('/reset-password/reset/', $this->getResetLink());

        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'E-mail de réinitialisation envoyé');
        self::assertSelectorTextContains('body', 'Ce lien expire dans 1 heure.');
    }

    public function testRequestFindsAccountRegardlessOfEmailCase(): void
    {
        // Regression: the generated code used a case-sensitive findOneBy() on the email
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->submitRequest(' Jane@Example.COM ');

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains($this->getResetEmail(), 'To', self::EMAIL);
    }

    public function testRequestForUnknownAccountLooksTheSameButSendsNothing(): void
    {
        // No account enumeration: same redirect and same page as for an existing account
        $this->submitRequest('nobody@example.com');

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(0);

        $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'E-mail de réinitialisation envoyé');
        self::assertSelectorTextContains('body', 'Ce lien expire dans 1 heure.');
    }

    public function testSecondRequestWithinThrottleDelayLooksTheSameButSendsNothing(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        self::assertQueuedEmailCount(1);

        $this->submitRequest(self::EMAIL);

        // Still the neutral page, and no second email (nor a message revealing the throttling)
        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(0);
        self::assertSame(1, $this->countResetRequests());
    }

    public function testEmptyEmailIsRejected(): void
    {
        $this->submitRequest('');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Cette valeur ne doit pas être vide.');
        self::assertQueuedEmailCount(0);
    }

    public function testCrossOriginRequestIsRejectedByCsrfProtection(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->client->request('POST', '/reset-password', [
            'reset_password_request_form' => ['_token' => 'csrf-token', 'email' => self::EMAIL],
        ], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le jeton CSRF est invalide.');
        self::assertQueuedEmailCount(0);
        self::assertSame(0, $this->countResetRequests());
    }

    public function testCheckEmailPageCanBeOpenedDirectly(): void
    {
        // A fake token is generated so the page never reveals whether a request exists
        $this->client->request('GET', '/reset-password/check-email');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Ce lien expire dans 1 heure.');
    }

    public function testResetLinkMovesTokenFromUrlToSession(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);

        $this->client->request('GET', $this->getResetLink());

        // The token must not stay in the URL (browser history, Referer header…)
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Réinitialiser votre mot de passe');
        self::assertSelectorTextSame('label[for="change_password_form_plainPassword_first"]', 'Nouveau mot de passe');
        self::assertSelectorTextSame('label[for="change_password_form_plainPassword_second"]', 'Confirmer le mot de passe');
        self::assertSelectorTextSame('button', 'Modifier le mot de passe');
    }

    public function testValidResetChangesPasswordAndRedirectsToLogin(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        $this->openResetForm();

        $this->submitNewPassword(self::NEW_PASSWORD, self::NEW_PASSWORD);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash--success', 'Votre mot de passe a bien été modifié. Vous pouvez maintenant vous connecter.');

        $user = UserFactory::repository()->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::NEW_PASSWORD));
        self::assertFalse($hasher->isPasswordValid($user, UserFactory::DEFAULT_PASSWORD));

        // The token is single-use: the request is deleted once the password has changed
        self::assertSame(0, $this->countResetRequests());
    }

    public function testNewPasswordWorksOnLoginForm(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        $this->openResetForm();
        $this->submitNewPassword(self::NEW_PASSWORD, self::NEW_PASSWORD);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => self::EMAIL,
            '_password' => self::NEW_PASSWORD,
        ]));

        self::assertResponseRedirects('/');
        self::assertSame(self::EMAIL, $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
    }

    public function testUsedResetLinkIsRejected(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        $link = $this->getResetLink();
        $this->openResetForm();
        $this->submitNewPassword(self::NEW_PASSWORD, self::NEW_PASSWORD);

        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Le lien de réinitialisation du mot de passe est invalide.');
    }

    public function testInvalidResetLinkIsRejected(): void
    {
        $this->client->request('GET', '/reset-password/reset/'.str_repeat('x', 40));
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Le lien de réinitialisation du mot de passe est invalide.');
    }

    public function testExpiredResetLinkIsRejected(): void
    {
        $user = UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);

        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('UPDATE '.ResetPasswordRequest::class.' r SET r.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();

        $this->openResetForm(expectForm: false);

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Le lien de votre e-mail est expiré.');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, UserFactory::DEFAULT_PASSWORD));
    }

    public function testResetPageWithoutTokenIsNotFound(): void
    {
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array{string, string} $passwords
     */
    #[DataProvider('invalidNewPasswords')]
    public function testInvalidNewPasswordIsRejected(array $passwords, string $expectedError): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        $this->openResetForm();

        $this->submitNewPassword(...$passwords);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', $expectedError);

        // Nothing changed, and the token can still be used to try again
        $user = UserFactory::repository()->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, UserFactory::DEFAULT_PASSWORD));
        self::assertSame(1, $this->countResetRequests());
    }

    /**
     * @return iterable<string, array{array{string, string}, string}>
     */
    public static function invalidNewPasswords(): iterable
    {
        yield 'passwords do not match' => [[self::NEW_PASSWORD, 'something-else-123'], 'Les mots de passe ne correspondent pas.'];
        yield 'empty password' => [['', ''], 'Veuillez saisir un mot de passe.'];
        // Same rule as registration (PasswordRequirements), not the 12 characters generated by Maker
        yield 'short password' => [['abcdefg', 'abcdefg'], 'Votre mot de passe doit contenir au moins 8 caractères.'];
    }

    public function testEightCharacterPasswordIsAcceptedLikeAtRegistration(): void
    {
        UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        $this->openResetForm();

        $this->submitNewPassword('abcdefgh', 'abcdefgh');

        self::assertResponseRedirects('/login');
    }

    public function testDeletingUserAlsoDeletesPendingResetRequests(): void
    {
        $user = UserFactory::createOne(['email' => self::EMAIL]);
        $this->submitRequest(self::EMAIL);
        self::assertSame(1, $this->countResetRequests());

        // onDelete: CASCADE on the foreign key (no FK violation when removing the user)
        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE '.User::class.' u WHERE u.id = :id')
            ->setParameter('id', $user->getId())
            ->execute();

        self::assertSame(0, $this->countResetRequests());
    }

    private function submitRequest(string $email): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->selectButton('Envoyer le lien')->form([
            'reset_password_request_form[email]' => $email,
        ]));
    }

    private function openResetForm(bool $expectForm = true): void
    {
        $this->client->request('GET', $this->getResetLink());
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();

        if ($expectForm) {
            self::assertResponseIsSuccessful();
        }
    }

    private function submitNewPassword(string $first, string $second): void
    {
        $this->client->submit($this->client->getCrawler()->selectButton('Modifier le mot de passe')->form([
            'change_password_form[plainPassword][first]' => $first,
            'change_password_form[plainPassword][second]' => $second,
        ]));
    }

    private function getResetEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function getResetLink(): string
    {
        $html = (string) $this->getResetEmail()->getHtmlBody();
        $link = (new Crawler($html))->filter('a')->attr('href');
        self::assertIsString($link);

        return $link;
    }

    private function countResetRequests(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ResetPasswordRequest::class)
            ->count([]);
    }
}
