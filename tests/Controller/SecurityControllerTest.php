<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SecurityControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'jane@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => self::EMAIL]);

        // Login throttling state lives in a filesystem cache pool that survives between tests and runs:
        // without this, failed logins from previous runs end up blocking the test client's IP.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testLoginPageIsDisplayedInFrench(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertPageTitleSame('Connexion');
        self::assertSelectorTextSame('h1', 'Connexion');
        self::assertSelectorTextSame('label[for="username"]', 'Adresse e-mail');
        self::assertSelectorTextSame('label[for="password"]', 'Mot de passe');
        self::assertSelectorTextSame('button[type="submit"]', 'Se connecter');
    }

    public function testLoginPageLinksToForgotPassword(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->click($crawler->selectLink('Mot de passe oublié ?')->link());

        self::assertResponseIsSuccessful();
        self::assertRouteSame('app_forgot_password_request');
    }

    public function testValidCredentialsLogTheUserIn(): void
    {
        $this->submitLogin(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
    }

    public function testLoginIgnoresEmailCaseAndSurroundingSpaces(): void
    {
        $this->submitLogin(' Jane@Example.COM ', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        // The stored (lowercase) email becomes the identifier, not what was typed
        self::assertSame(self::EMAIL, $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->submitLogin(self::EMAIL, 'wrong-password');

        $this->assertLoginFailed();
        // The email is kept so the user only has to retype the password
        self::assertInputValueSame('_username', self::EMAIL);
    }

    public function testUnknownEmailIsRejected(): void
    {
        $this->submitLogin('unknown@example.com', UserFactory::DEFAULT_PASSWORD);

        $this->assertLoginFailed();
    }

    public function testCrossOriginLoginIsRejectedByCsrfProtection(): void
    {
        $this->client->request('POST', '/login', [
            '_username' => self::EMAIL,
            '_password' => UserFactory::DEFAULT_PASSWORD,
            '_csrf_token' => 'csrf-token',
        ], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextSame('form div', 'Jeton CSRF invalide.');
        $this->assertNotAuthenticated();
    }

    public function testLoggedInUserIsRedirectedAwayFromLoginPage(): void
    {
        $this->client->loginUser(UserFactory::find(['email' => self::EMAIL]));

        $this->client->request('GET', '/login');

        self::assertResponseRedirects('/');
    }

    public function testLogoutLogsTheUserOut(): void
    {
        $this->client->loginUser(UserFactory::find(['email' => self::EMAIL]));

        $this->client->request('GET', '/logout');

        self::assertResponseRedirects('/');
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
    }

    private function submitLogin(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $email,
            '_password' => $password,
        ]);
    }

    private function assertLoginFailed(): void
    {
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextSame('form div', 'Identifiants invalides.');
        $this->assertNotAuthenticated();
    }

    private function assertNotAuthenticated(): void
    {
        self::assertNull($this->client->getContainer()->get('security.token_storage')->getToken());
    }
}
