<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\UserFactory;
use App\Tests\ClearsRateLimiters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SecurityControllerTest extends WebTestCase
{
    use ClearsRateLimiters;
    use Factories;
    use ResetDatabase;

    private const string EMAIL = 'jane@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        UserFactory::createOne(['email' => self::EMAIL]);

        $this->clearRateLimiters();
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

    public function testLogoutButtonInHeaderLogsTheUserOut(): void
    {
        $this->client->loginUser(UserFactory::find(['email' => self::EMAIL]));
        $this->client->request('GET', '/');

        $this->client->submitForm('Déconnexion');

        self::assertResponseRedirects('/');
        $this->assertNotAuthenticated();
        $this->client->followRedirect();
        self::assertSelectorExists('header a[href="/login"]');
        self::assertSelectorNotExists('header form[action="/logout"]');
    }

    public function testLogoutButtonIsHiddenFromAnonymousVisitors(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorNotExists('header form[action="/logout"]');
        self::assertSelectorExists('header a[href="/login"]');
        self::assertSelectorExists('header a[href="/register"]');
    }

    public function testLogoutViaGetIsNotAllowed(): void
    {
        // A GET logout could be triggered by a simple <img src="/logout"> on another site
        $this->client->loginUser(UserFactory::find(['email' => self::EMAIL]));

        $this->client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(405);
        $this->assertStillAuthenticated();
    }

    public function testLogoutWithoutCsrfTokenIsRefused(): void
    {
        $this->client->loginUser(UserFactory::find(['email' => self::EMAIL]));

        $this->client->request('POST', '/logout');

        self::assertResponseStatusCodeSame(403);
        $this->assertStillAuthenticated();
    }

    public function testCrossOriginLogoutIsRefused(): void
    {
        $this->client->loginUser(UserFactory::find(['email' => self::EMAIL]));

        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        $this->assertStillAuthenticated();
    }

    public function testLoginIsBlockedAfterFiveFailedAttempts(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin(self::EMAIL, 'wrong-password');
            $this->assertLoginFailed();
        }

        // Even the right password is refused once the limit is reached (the minutes shown are rounded up, 15 or 16)
        $this->submitLogin(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('form div', 'Trop de tentatives de connexion échouées');
        $this->assertNotAuthenticated();
    }

    public function testThrottlingOfOneAccountDoesNotBlockOtherAccounts(): void
    {
        UserFactory::createOne(['email' => 'john@example.com']);
        for ($i = 0; $i < 5; ++$i) {
            $this->submitLogin(self::EMAIL, 'wrong-password');
        }

        $this->submitLogin('john@example.com', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $this->assertStillAuthenticated();
    }

    public function testRememberMeKeepsTheUserLoggedInAfterSessionExpires(): void
    {
        $this->submitLogin(self::EMAIL, UserFactory::DEFAULT_PASSWORD, rememberMe: true);
        self::assertResponseRedirects('/');
        self::assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));

        // Simulate the browser being closed: the session cookie is gone, only REMEMBERME remains
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->client->getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
        self::assertSelectorExists('header form[action="/logout"]');
    }

    public function testNoRememberMeCookieWhenCheckboxIsNotTicked(): void
    {
        $this->submitLogin(self::EMAIL, UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
    }

    public function testTamperedRememberMeCookieIsIgnored(): void
    {
        $this->client->getCookieJar()->set(new Cookie('REMEMBERME', 'forged-value'));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $this->assertNotAuthenticated();
    }

    public function testLogoutClearsRememberMeCookie(): void
    {
        $this->submitLogin(self::EMAIL, UserFactory::DEFAULT_PASSWORD, rememberMe: true);
        $this->client->followRedirect();

        $this->client->submitForm('Déconnexion');

        self::assertResponseRedirects('/');
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->client->followRedirect();
        $this->assertNotAuthenticated();
    }

    private function submitLogin(string $email, string $password, bool $rememberMe = false): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $email,
            '_password' => $password,
            '_remember_me' => $rememberMe,
        ]);
    }

    private function assertStillAuthenticated(): void
    {
        self::assertNotNull($this->client->getContainer()->get('security.token_storage')->getToken());
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
