<?php

declare(strict_types=1);

namespace Edict\Auth;

use Edict\Clock;
use Edict\Database\Row;

/**
 * The single admin account: creation and reset (CLI only) and
 * password + TOTP verification (login form).
 */
final readonly class AdminAccount
{
    private const BCRYPT_COST = 12;
    private const MIN_PASSWORD = 12;
    private const MAX_PASSWORD_BYTES = 72; // bcrypt ignores anything beyond this

    public function __construct(
        private \PDO $pdo,
        private SecretBox $box,
        private Clock $clock,
        private LoginThrottle $throttle,
    ) {}

    /**
     * @return string the raw TOTP secret, to be shown to the admin exactly once
     */
    public function create(string $username, #[\SensitiveParameter] string $password): string
    {
        $username = self::normaliseUsername($username);
        if ($username === '' || strlen($username) > 64) {
            throw new \InvalidArgumentException('Username must be 1 to 64 characters.');
        }
        self::assertPasswordPolicy($password);

        $secret = Totp::generateSecret();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->query('SELECT 1 FROM app_user WHERE is_admin');
            if ($stmt !== false && $stmt->fetchColumn() !== false) {
                throw new \DomainException('An admin already exists. Use admin:reset instead.');
            }

            $insert = $this->pdo->prepare('INSERT INTO app_user (display_name, is_admin) VALUES (?, true) RETURNING id');
            $insert->execute([$username]);
            $userId = $insert->fetchColumn();

            $this->pdo->prepare(
                'INSERT INTO admin_credential (user_id, username, password_hash, totp_secret) VALUES (?, ?, ?, ?)',
            )->execute([$userId, $username, self::hash($password), $this->box->seal($secret)]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $secret;
    }

    /**
     * Sets a new password and TOTP secret, removes all passkeys, ends all admin
     * sessions and lifts any throttle on the username. A reset means something was
     * lost or stolen, so every way in that could be affected goes.
     *
     * @return array{username: string, secret: string} the raw TOTP secret is shown exactly once
     */
    public function reset(#[\SensitiveParameter] string $password): array
    {
        self::assertPasswordPolicy($password);

        $secret = Totp::generateSecret();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE admin_credential SET password_hash = ?, totp_secret = ?, totp_last_step = 0, updated_at = now()
                 RETURNING user_id, username',
            );
            $stmt->execute([self::hash($password), $this->box->seal($secret)]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new \DomainException('No admin exists yet. Use admin:create first.');
            }

            $this->pdo->prepare('DELETE FROM session WHERE user_id = ?')->execute([Row::int($row, 'user_id')]);
            $this->pdo->prepare('DELETE FROM passkey WHERE user_id = ?')->execute([Row::int($row, 'user_id')]);
            $this->throttle->clearUsername(Row::str($row, 'username'));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['username' => Row::str($row, 'username'), 'secret' => $secret];
    }

    public function usernameOf(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT username FROM admin_credential WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        return is_array($row) ? Row::str($row, 'username') : null;
    }

    /**
     * @return int|null the admin's user ID, or null when anything is wrong
     *                  (callers must not reveal which factor failed)
     */
    public function verify(string $username, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $code): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.user_id, c.password_hash, c.totp_secret
             FROM admin_credential c JOIN app_user u ON u.id = c.user_id
             WHERE c.username = ? AND u.is_admin AND u.deleted_at IS NULL',
        );
        $stmt->execute([self::normaliseUsername($username)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            // Spend the same time as a real check, so response timing doesn't reveal the username.
            self::hash($password);

            return null;
        }

        if (!password_verify($password, Row::str($row, 'password_hash'))) {
            return null;
        }

        $step = Totp::matchStep($this->box->open(Row::str($row, 'totp_secret')), trim($code), $this->clock->now());
        if ($step === null) {
            return null;
        }

        // Atomic: a code can be used once, even by two simultaneous requests.
        $userId = Row::int($row, 'user_id');
        $claim = $this->pdo->prepare('UPDATE admin_credential SET totp_last_step = ? WHERE user_id = ? AND totp_last_step < ?');
        $claim->execute([$step, $userId, $step]);

        return $claim->rowCount() === 1 ? $userId : null;
    }

    public static function normaliseUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    private static function assertPasswordPolicy(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new \InvalidArgumentException(sprintf('Password must have at least %d characters.', self::MIN_PASSWORD));
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new \InvalidArgumentException(sprintf('Password must not exceed %d bytes.', self::MAX_PASSWORD_BYTES));
        }
    }

    private static function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }
}
