<?php

declare(strict_types=1);

namespace Edict\Auth;

use Edict\Auth\OAuth\ProviderIdentity;
use Edict\Clock;
use Edict\Database\Row;

/**
 * Accounts and their external identities. Identities are matched on provider and
 * the provider's stable ID only. Nothing is ever merged by name or email.
 */
final readonly class Accounts
{
    public const DELETED_NAME = '[deleted user]';
    public const MAX_NAME_LENGTH = 64;
    private const FALLBACK_NAME = 'Reader';

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * Cleans a display name: no control, format or zero-width characters (they
     * allow text direction tricks and look-alikes), and single spaces only.
     *
     * @return string|null the clean name, or null if nothing usable remains
     */
    public static function cleanName(string $name): ?string
    {
        $clean = preg_replace('/\s+/u', ' ', $name);
        $clean = $clean === null ? null : preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', '', $clean);
        $clean = $clean === null ? '' : trim($clean);

        return $clean === '' ? null : $clean;
    }

    /**
     * Returns the account of this identity, creating one on first login.
     *
     * @return array{id: int, isAdmin: bool, created: bool}
     */
    public function findOrCreate(ProviderIdentity $identity): array
    {
        $owner = $this->owner($identity->provider, $identity->subject);
        if ($owner !== null) {
            return $owner + ['created' => false];
        }

        $name = self::cleanName($identity->name) ?? self::FALLBACK_NAME;
        if ($name === self::DELETED_NAME) {
            $name = self::FALLBACK_NAME;
        }
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        $created = $this->transaction(function () use ($identity, $name): ?int {
            $insert = $this->pdo->prepare('INSERT INTO app_user (display_name) VALUES (?) RETURNING id');
            $insert->execute([$name]);
            $id = Row::int(['id' => $insert->fetchColumn()], 'id');

            if ($this->addIdentity($id, $identity->provider, $identity->subject)) {
                return $id;
            }

            // A concurrent first login won. Throwing away our row needs no rollback.
            $this->pdo->prepare('DELETE FROM app_user WHERE id = ?')->execute([$id]);

            return null;
        });

        if ($created !== null) {
            return ['id' => $created, 'isAdmin' => false, 'created' => true];
        }

        $owner = $this->owner($identity->provider, $identity->subject);
        if ($owner === null) {
            throw new \RuntimeException('Identity vanished during concurrent login');
        }

        return $owner + ['created' => false];
    }

    /**
     * Adds a provider login to an existing account.
     *
     * @return 'linked'|'already'|'taken'|'provider-in-use'
     */
    public function link(int $userId, ProviderIdentity $identity): string
    {
        $owner = $this->owner($identity->provider, $identity->subject);
        if ($owner !== null) {
            return $owner['id'] === $userId ? 'already' : 'taken';
        }

        foreach ($this->identities($userId) as $existing) {
            if ($existing['provider'] === $identity->provider) {
                return 'provider-in-use';
            }
        }

        return $this->addIdentity($userId, $identity->provider, $identity->subject) ? 'linked' : 'taken';
    }

    /**
     * @return list<array{provider: string, subject: string}>
     */
    public function identities(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT provider, subject FROM user_identity WHERE user_id = ? ORDER BY provider');
        $stmt->execute([$userId]);

        $identities = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $identities[] = ['provider' => Row::str($row, 'provider'), 'subject' => Row::str($row, 'subject')];
            }
        }

        return $identities;
    }

    /**
     * @return bool false if the name is not acceptable
     */
    public function rename(int $userId, string $name): bool
    {
        $clean = self::cleanName($name);
        if ($clean === null || mb_strlen($clean) > self::MAX_NAME_LENGTH || $clean === self::DELETED_NAME) {
            return false;
        }

        $this->pdo->prepare('UPDATE app_user SET display_name = ? WHERE id = ?')->execute([$clean, $userId]);

        return true;
    }

    /**
     * Deletes a reader's account: personal data goes, the row stays so that later
     * content can show "[deleted user]". The next login with the same provider
     * account starts a new, unconnected account.
     *
     * @throws \DomainException for the admin account
     */
    public function delete(int $userId): void
    {
        $this->transaction(function () use ($userId): void {
            $stmt = $this->pdo->prepare('SELECT is_admin FROM app_user WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (is_array($row) && Row::bool($row, 'is_admin')) {
                throw new \DomainException('The admin account cannot be deleted');
            }

            $this->erase($userId);
        });
    }

    /**
     * Allow-lists a provider account for admin login. If a reader account already
     * holds that identity, it moves to the admin. A reader left without any login
     * is deleted, because nobody could reach it any more.
     *
     * @return 'added'|'unchanged'|'moved'
     *
     * @throws \DomainException if there is no admin yet, or the admin already has a different account of this provider
     */
    public function allowForAdmin(string $provider, string $subject): string
    {
        return $this->transaction(function () use ($provider, $subject): string {
            $adminId = $this->adminId();

            $owner = $this->owner($provider, $subject);
            if ($owner !== null && $owner['id'] === $adminId) {
                return 'unchanged';
            }

            foreach ($this->identities($adminId) as $existing) {
                if ($existing['provider'] === $provider) {
                    throw new \DomainException("The admin already has a $provider account allow-listed; remove it first");
                }
            }

            if ($owner === null) {
                $this->addIdentity($adminId, $provider, $subject);

                return 'added';
            }

            $this->pdo->prepare('UPDATE user_identity SET user_id = ? WHERE provider = ? AND subject = ?')
                ->execute([$adminId, $provider, $subject]);
            $this->pdo->prepare('DELETE FROM session WHERE user_id = ?')->execute([$owner['id']]);
            if ($this->identities($owner['id']) === []) {
                $this->erase($owner['id']);
            }

            return 'moved';
        });
    }

    /**
     * Removes an allow-listed account and ends all admin sessions, since one of
     * them may have been opened with the account being removed.
     *
     * @return bool false if that account was not allow-listed
     *
     * @throws \DomainException if there is no admin yet
     */
    public function disallowForAdmin(string $provider, string $subject): bool
    {
        return $this->transaction(function () use ($provider, $subject): bool {
            $adminId = $this->adminId();

            $stmt = $this->pdo->prepare('DELETE FROM user_identity WHERE provider = ? AND subject = ? AND user_id = ?');
            $stmt->execute([$provider, $subject, $adminId]);
            if ($stmt->rowCount() === 0) {
                return false;
            }

            $this->pdo->prepare('DELETE FROM session WHERE user_id = ?')->execute([$adminId]);

            return true;
        });
    }

    /**
     * @return list<array{provider: string, subject: string}>
     *
     * @throws \DomainException if there is no admin yet
     */
    public function adminIdentities(): array
    {
        return $this->identities($this->adminId());
    }

    private function adminId(): int
    {
        $stmt = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        $row = $stmt === false ? false : $stmt->fetch();
        if (!is_array($row)) {
            throw new \DomainException('No admin exists yet. Run admin:create first');
        }

        return Row::int($row, 'id');
    }

    private function erase(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM user_identity WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM session WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM vote WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('UPDATE app_user SET display_name = ?, deleted_at = to_timestamp(?) WHERE id = ?')
            ->execute([self::DELETED_NAME, $this->clock->now(), $userId]);
    }

    /**
     * @return array{id: int, isAdmin: bool}|null
     */
    private function owner(string $provider, string $subject): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.is_admin FROM user_identity i JOIN app_user u ON u.id = i.user_id
             WHERE i.provider = ? AND i.subject = ?',
        );
        $stmt->execute([$provider, $subject]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => Row::int($row, 'id'), 'isAdmin' => Row::bool($row, 'is_admin')] : null;
    }

    /**
     * @return bool false if a concurrent request got there first
     */
    private function addIdentity(int $userId, string $provider, string $subject): bool
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_identity (provider, subject, user_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
        $stmt->execute([$provider, $subject, $userId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
