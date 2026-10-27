<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\helpers;

use yii\db\Exception as DbException;

/**
 * Busy shops get the occasional database deadlock: the database picks a loser and asks it to try
 * again. Customer-facing writes do exactly that rather than showing an error.
 */
final class Deadlock
{
    /**
     * @template T
     * @param callable(): T $work must be safe to run again from the start
     * @return T
     */
    public static function retry(callable $work, int $attempts = 3): mixed
    {
        for ($attempt = 1;; $attempt++) {
            try {
                return $work();
            } catch (DbException $e) {
                if (!self::isDeadlock($e) || $attempt >= $attempts) {
                    throw $e;
                }

                // Different waits, so the two losers don't collide again.
                usleep(random_int(30000, 150000));
            }
        }
    }

    public static function isDeadlock(DbException $e): bool
    {
        $info = $e->errorInfo;

        return ($info[0] ?? null) === '40001'   // serialization failure (MySQL, PostgreSQL)
            || ($info[0] ?? null) === '40P01'   // deadlock detected (PostgreSQL)
            || (int) ($info[1] ?? 0) === 1213;  // MySQL deadlock
    }
}
