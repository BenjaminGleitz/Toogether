<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * access_control and role_hierarchy from config/packages/security.yaml.
 *
 * No /admin route exists yet (backoffice comes later), so a real request would stop at the router (404)
 * before the firewall: the configuration is checked through the security services instead.
 */
final class AccessControlTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPaths(): iterable
    {
        yield 'admin root' => ['/admin'];
        yield 'admin sub page' => ['/admin/cities/1/edit'];
    }

    #[DataProvider('adminPaths')]
    public function testAdminPathsRequireRoleAdmin(string $path): void
    {
        [$attributes] = $this->accessMap()->getPatterns(Request::create($path));

        self::assertSame(['ROLE_ADMIN'], $attributes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicPaths(): iterable
    {
        yield 'home' => ['/'];
        yield 'login' => ['/login'];
        yield 'register' => ['/register'];
        yield 'admin not at the start of the path' => ['/events/admin'];
    }

    #[DataProvider('publicPaths')]
    public function testOtherPathsHaveNoAccessControlRule(string $path): void
    {
        [$attributes] = $this->accessMap()->getPatterns(Request::create($path));

        self::assertNull($attributes);
    }

    public function testAdminInheritsUserRole(): void
    {
        self::assertTrue($this->decide(['ROLE_ADMIN'], 'ROLE_USER'));
        self::assertTrue($this->decide(['ROLE_ADMIN'], 'ROLE_ADMIN'));
    }

    public function testUserIsNotGrantedAdminRole(): void
    {
        self::assertFalse($this->decide(['ROLE_USER'], 'ROLE_ADMIN'));
    }

    private function accessMap(): AccessMapInterface
    {
        return self::getContainer()->get('security.access_map');
    }

    /**
     * @param list<string> $roles
     */
    private function decide(array $roles, string $attribute): bool
    {
        $token = new UsernamePasswordToken(new User(), 'main', $roles);

        return self::getContainer()->get(AccessDecisionManagerInterface::class)->decide($token, [$attribute]);
    }
}
