<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Unverified-email banner and POST /verify/resend (RegistrationController::resendVerification).
 */
final class VerifyEmailResendTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private const string BANNER_MESSAGE = 'Un e-mail de confirmation vous a été envoyé.';
    private const string FLASH_SENT = "L'e-mail de vérification a été renvoyé avec succès.";
    private const string FLASH_ALREADY_VERIFIED = 'Votre adresse e-mail est déjà vérifiée.';
    private const string FLASH_TOO_MANY = 'Vous avez demandé trop de renvois';
    private const int RESEND_LIMIT = 3;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        // The limiter state lives in a filesystem cache pool that survives between tests and runs
        // (and user ids restart at 1 after each ResetDatabase): start every test with a clean slate.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testBannerIsShownToUnverifiedUser(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.banner', self::BANNER_MESSAGE);
        self::assertSelectorTextSame('.banner button[type="submit"]', "Renvoyer l'e-mail de vérification");
        self::assertSelectorExists('.banner form[method="post"][action="/verify/resend"]');
        self::assertSelectorExists('.banner input[name="_token"]');
    }

    public function testBannerIsHiddenForVerifiedUser(): void
    {
        $this->client->loginUser(UserFactory::createOne(['isVerified' => true]));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.banner');
    }

    public function testBannerIsHiddenForAnonymousVisitor(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.banner');
    }

    public function testResendQueuesAConfirmationEmailThatVerifiesTheAccount(): void
    {
        $user = UserFactory::createOne(['email' => 'jane@example.com', 'firstname' => 'Jane']);
        $this->client->loginUser($user);

        $this->client->submit($this->getBannerForm());

        self::assertResponseRedirects('/');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'jane@example.com');
        self::assertEmailHeaderSame($email, 'Subject', 'Confirmez votre adresse e-mail');
        self::assertEmailHtmlBodyContains($email, 'Bonjour Jane');

        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash--success', self::FLASH_SENT);

        // The resent link works like the original one
        $link = (new Crawler((string) $email->getHtmlBody()))->filter('a')->attr('href');
        self::assertIsString($link);
        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertTrue($this->reloadUser($user)->isVerified());
        self::assertSelectorNotExists('.banner');
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('POST', '/verify/resend', ['_token' => 'whatever']);

        self::assertResponseRedirects('/login');
        self::assertQueuedEmailCount(0);
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('POST', '/verify/resend', ['_token' => 'forged-token']);

        $this->assertCsrfRejected();
    }

    public function testMissingCsrfTokenIsRejected(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('POST', '/verify/resend');

        $this->assertCsrfRejected();
    }

    public function testGetRequestIsNotAllowed(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/verify/resend');

        self::assertResponseStatusCodeSame(405);
        self::assertQueuedEmailCount(0);
    }

    public function testAlreadyVerifiedUserGetsAnInfoMessageAndNoEmail(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        // Banner displayed, then the account gets verified (e.g. link clicked in another tab)
        $form = $this->getBannerForm();
        $this->setVerified($user, true);

        $this->client->submit($form);

        self::assertResponseRedirects('/');
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.flash--info', self::FLASH_ALREADY_VERIFIED);
        self::assertSelectorNotExists('.banner');
    }

    public function testAlreadyVerifiedUserDoesNotConsumeTheLimit(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);
        $form = $this->getBannerForm();
        $this->setVerified($user, true);

        for ($i = 0; $i < self::RESEND_LIMIT + 1; ++$i) {
            $this->client->submit($form);
        }

        // Back to unverified (e.g. email changed later): the full quota is still available
        $this->setVerified($user, false);
        $this->client->submit($form);

        self::assertQueuedEmailCount(1);
    }

    public function testResendIsBlockedAfterThreeEmailsWithinAnHour(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $form = $this->getBannerForm();

        for ($i = 1; $i <= self::RESEND_LIMIT; ++$i) {
            $this->client->submit($form);
            self::assertQueuedEmailCount(1, message: \sprintf('Resend #%d should queue an email', $i));
        }

        $this->client->submit($form);

        self::assertResponseRedirects('/');
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--warning', self::FLASH_TOO_MANY);
        // The banner stays: the user can come back later
        self::assertSelectorExists('.banner');
    }

    public function testLimitIsPerUser(): void
    {
        $this->client->loginUser(UserFactory::createOne());
        $form = $this->getBannerForm();
        for ($i = 0; $i <= self::RESEND_LIMIT; ++$i) {
            $this->client->submit($form);
        }
        self::assertQueuedEmailCount(0);

        $this->client->loginUser(UserFactory::createOne());
        $this->client->submit($this->getBannerForm());

        self::assertQueuedEmailCount(1);
    }

    private function getBannerForm(): Form
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $crawler->filter('.banner form')->form();
    }

    /**
     * #[IsCsrfTokenValid] throws an InvalidCsrfTokenException (an AuthenticationException): the firewall
     * answers with a redirect to the login page, which sends the still logged-in user back home.
     */
    private function assertCsrfRejected(): void
    {
        self::assertResponseRedirects('/login');
        self::assertQueuedEmailCount(0);

        $this->client->followRedirect();
        self::assertResponseRedirects('/');
    }

    /**
     * The client reboots the kernel between requests: reload the user from the current entity manager,
     * otherwise the change would be made on a detached entity and never flushed.
     */
    private function setVerified(User $user, bool $verified): void
    {
        $this->reloadUser($user)->setIsVerified($verified);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function reloadUser(User $user): User
    {
        $reloaded = UserFactory::repository()->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
