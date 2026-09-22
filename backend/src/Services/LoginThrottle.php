<?php

declare(strict_types=1);

namespace CyberGuard\Campus\Services;

use Closure;
use CyberGuard\Campus\Repositories\LoginThrottleRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sign-in throttling (APP-07.4.1): progressive delays, never an account lock.
 *
 * Two buckets are consumed by every sign-in attempt, before any user lookup or password check:
 *  - source_account: one identifier from one client address — 5 free attempts;
 *  - account: one identifier from any address — 10 free attempts (a distributed attack on one
 *    account is slowed down too).
 * There is no address-only bucket: many campus users share one NAT address, and one of them
 * failing on their own identifier must not slow down the others.
 *
 * Past its free attempts a bucket waits 30 s, then 60 s, 120 s… up to 15 minutes, before the
 * next attempt. A refused attempt (429) is not counted and does not extend the wait, so no one
 * can lock an account for longer than the current delay; users.status is never changed.
 * A bucket starts again from zero after 1 hour without attempts, and a successful sign-in
 * clears both. The attempt is counted before the password is checked, under row locks, so
 * concurrent requests cannot exceed the budget.
 */
final class LoginThrottle
{
    public const FREE_ATTEMPTS = ['source_account' => 5, 'account' => 10];
    public const BASE_DELAY_SECONDS = 30;
    public const MAX_DELAY_SECONDS = 900;
    public const RESET_AFTER_IDLE_SECONDS = 3600;
    public const PURGE_AFTER_IDLE_SECONDS = 7200;

    /** Longest identifier that can match an account (users.email is VARCHAR(254)). */
    private const MAX_IDENTIFIER_LENGTH = 255;

    /**
     * @param (Closure(): float)|null $clock Current Unix time with microseconds; defaults to
     *                                       microtime(true). Injected by tests only.
     */
    public function __construct(
        private readonly LoginThrottleRepository $repository,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * Counts one sign-in attempt. Null when it may proceed; otherwise the number of seconds to
     * wait (the attempt was refused and not counted). Same answer whether the account exists.
     */
    public function attempt(#[\SensitiveParameter] string $identifier, string $source): ?int
    {
        $now = $this->now();
        $keys = $this->keys($identifier, $source);

        $this->repository->ensure($keys, $this->stamp($now));

        $wait = $this->repository->transaction(function () use ($keys, $now): ?int {
            $rows = $this->repository->lock(array_values($keys));
            $wait = 0;

            foreach ($rows as $row) {
                if ($row['blocked_until'] !== null) {
                    $remaining = $this->time($row['blocked_until']) - $now;

                    if ($remaining > 0) {
                        $wait = max($wait, (int) ceil($remaining));
                    }
                }
            }

            if ($wait > 0) {
                return $wait;
            }

            foreach ($keys as $scope => $key) {
                $row = $rows[$key] ?? null;
                $idle = $row === null
                    || $now - $this->time($row['last_attempt_at']) >= self::RESET_AFTER_IDLE_SECONDS;
                $attempts = ($idle ? 0 : $row['attempts']) + 1;
                $free = self::FREE_ATTEMPTS[$scope];
                $blockedUntil = $attempts >= $free
                    ? $this->stamp($now + $this->delay($attempts - $free))
                    : null;

                $this->repository->save($key, $scope, $attempts, $this->stamp($now), $blockedUntil);
            }

            return null;
        });

        $this->repository->purgeIdleSince($this->stamp($now - self::PURGE_AFTER_IDLE_SECONDS));

        return $wait;
    }

    /** A successful sign-in: both buckets start again from zero. */
    public function clear(#[\SensitiveParameter] string $identifier, string $source): void
    {
        $this->repository->delete(array_values($this->keys($identifier, $source)));
    }

    /** Wait imposed after the attempt that is $over attempts past the free ones (0 = the last free one). */
    public static function delay(int $over): int
    {
        return (int) min(self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * 2 ** min($over, 20));
    }

    /** @return array{account: string, source_account: string} */
    private function keys(#[\SensitiveParameter] string $identifier, string $source): array
    {
        // Same trimming as AuthenticationService; longer values cannot match any account.
        $identifier = mb_substr(trim($identifier), 0, self::MAX_IDENTIFIER_LENGTH);
        $packed = @inet_pton($source);
        $source = $packed !== false ? (string) inet_ntop($packed) : $source;

        return $this->repository->keys($identifier, $source);
    }

    private function now(): float
    {
        return $this->clock === null ? microtime(true) : ($this->clock)();
    }

    private function stamp(float $time): string
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $time), new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    private function time(string $stamp): float
    {
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $stamp, $utc)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stamp, $utc);

        return (float) $date->format('U.u');
    }
}
