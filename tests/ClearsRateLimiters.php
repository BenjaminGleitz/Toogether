<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Rate limiter state (login_throttling, #[RateLimit], resend_verification) lives in the filesystem
 * "cache.rate_limiter" pool: it survives between tests and runs, every test client shares the same IP
 * and user ids restart at 1 after each ResetDatabase. Call this right after createClient() in setUp().
 */
trait ClearsRateLimiters
{
    private function clearRateLimiters(): void
    {
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
}
