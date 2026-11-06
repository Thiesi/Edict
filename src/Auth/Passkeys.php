<?php

declare(strict_types=1);

namespace Edict\Auth;

use Edict\Clock;
use Edict\Config;
use Edict\Database\Row;
use lbuchs\WebAuthn\WebAuthn;

/**
 * WebAuthn registration and login for the admin account. User verification is
 * always required, so a passkey replaces both password and authenticator code.
 * Passkeys are bound to the site's host name: they don't move between staging
 * and production, or to another domain.
 */
final readonly class Passkeys
{
    public const CHALLENGE_TTL = 300;
    private const TIMEOUT_SECONDS = 120;
    private const MAX_NAME_LENGTH = 64;

    public function __construct(private \PDO $pdo, private Config $config, private Clock $clock) {}

    /**
     * @return array{options: \stdClass, challenge: string} the challenge is binary
     */
    public function creationOptions(int $userId, string $displayName): array
    {
        $webAuthn = $this->webAuthn();
        $options = $webAuthn->getCreateArgs(
            'edict-user-' . $userId,
            $displayName,
            $displayName,
            self::TIMEOUT_SECONDS,
            true, // discoverable, so login needs no username
            true, // user verification
            null,
            array_map(self::decode(...), array_column($this->all($userId), 'credential_id')),
        );

        // We don't care which device made a passkey, and asking would show people a
        // privacy prompt. The library would ask, since it accepts more than "none".
        $options->publicKey->attestation = 'none';

        return ['options' => $options, 'challenge' => $webAuthn->getChallenge()->getBinaryString()];
    }

    /**
     * @return array{options: \stdClass, challenge: string} the challenge is binary
     */
    public function assertionOptions(): array
    {
        $webAuthn = $this->webAuthn();
        $options = $webAuthn->getGetArgs([], self::TIMEOUT_SECONDS, true, true, true, true, true, true);

        return ['options' => $options, 'challenge' => $webAuthn->getChallenge()->getBinaryString()];
    }

    /**
     * @throws PasskeyException
     */
    public function register(int $userId, string $name, string $clientData, string $attestation, string $challenge): void
    {
        $this->assertOurOrigin($clientData);
        $clean = self::cleanName($name);
        if ($clean === null) {
            throw new PasskeyException('Unusable passkey name');
        }

        $webAuthn = $this->webAuthn();
        try {
            $data = $webAuthn->processCreate($clientData, $attestation, $challenge, true, true, false, false);
        } catch (\Throwable $e) {
            // Not just WebAuthnException: crafted input also raises TypeErrors in the library.
            throw new PasskeyException($e->getMessage(), 0, $e);
        }

        $credentialId = $data->credentialId;
        $publicKey = $data->credentialPublicKey;
        if (!is_string($credentialId) || !is_string($publicKey)) {
            throw new PasskeyException('The authenticator used an unsupported key type');
        }

        $this->consumeChallenge($challenge);

        $insert = $this->pdo->prepare(
            'INSERT INTO passkey (user_id, credential_id, public_key, sign_count, name, created_at)
             VALUES (?, ?, ?, ?, ?, to_timestamp(?)) ON CONFLICT DO NOTHING',
        );
        $insert->execute([$userId, self::encode($credentialId), $publicKey, $webAuthn->getSignatureCounter() ?? 0, $clean, $this->clock->now()]);
        if ($insert->rowCount() !== 1) {
            throw new PasskeyException('That passkey is already registered');
        }
    }

    /**
     * @return int the signed-in user's ID
     *
     * @throws PasskeyException
     */
    public function verifyLogin(string $credentialId, string $clientData, string $authenticatorData, string $signature, string $challenge): int
    {
        $this->assertOurOrigin($clientData);

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.user_id, p.public_key, p.sign_count
             FROM passkey p JOIN app_user u ON u.id = p.user_id
             WHERE p.credential_id = ? AND u.is_admin AND u.deleted_at IS NULL',
        );
        $stmt->execute([self::encode($credentialId)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new PasskeyException('Unknown passkey');
        }

        $webAuthn = $this->webAuthn();
        try {
            $webAuthn->processGet(
                $clientData,
                $authenticatorData,
                $signature,
                Row::str($row, 'public_key'),
                $challenge,
                self::isSynced($authenticatorData) ? null : Row::int($row, 'sign_count'),
                true,
                true,
            );
        } catch (\Throwable $e) {
            // Not just WebAuthnException: crafted input also raises TypeErrors in the library.
            throw new PasskeyException($e->getMessage(), 0, $e);
        }

        $this->consumeChallenge($challenge);

        $this->pdo->prepare('UPDATE passkey SET sign_count = ?, last_used_at = to_timestamp(?) WHERE id = ?')
            ->execute([$webAuthn->getSignatureCounter() ?? 0, $this->clock->now(), Row::int($row, 'id')]);

        return Row::int($row, 'user_id');
    }

    /**
     * @return list<array{id: int, credential_id: string, name: string, created_at: string, last_used_at: ?string}>
     */
    public function all(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, credential_id, name, to_char(created_at, 'YYYY-MM-DD') AS created_at,
                    to_char(last_used_at, 'YYYY-MM-DD') AS last_used_at
             FROM passkey WHERE user_id = ? ORDER BY id",
        );
        $stmt->execute([$userId]);

        $passkeys = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $lastUsed = $row['last_used_at'] ?? null;
                $passkeys[] = [
                    'id' => Row::int($row, 'id'),
                    'credential_id' => Row::str($row, 'credential_id'),
                    'name' => Row::str($row, 'name'),
                    'created_at' => Row::str($row, 'created_at'),
                    'last_used_at' => is_string($lastUsed) ? $lastUsed : null,
                ];
            }
        }

        return $passkeys;
    }

    public function remove(int $userId, int $id): void
    {
        $this->pdo->prepare('DELETE FROM passkey WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    }

    /**
     * @return string|null the clean name, or null if it isn't usable
     */
    public static function cleanName(string $name): ?string
    {
        $clean = Accounts::cleanName($name);

        return $clean === null || mb_strlen($clean) > self::MAX_NAME_LENGTH ? null : $clean;
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * Strict base64url. An invalid value decodes to an empty string, which fails
     * verification like any other wrong value.
     */
    public static function decode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }

    /**
     * The backup-eligible flag. Copies of a synced passkey don't share a counter, so
     * going backwards proves nothing. The flag is covered by the signature.
     */
    private static function isSynced(string $authenticatorData): bool
    {
        return isset($authenticatorData[32]) && (ord($authenticatorData[32]) & 0x08) !== 0;
    }

    private function webAuthn(): WebAuthn
    {
        $host = parse_url($this->config->appUrl, PHP_URL_HOST);
        if (!is_string($host)) {
            throw new \RuntimeException('APP_URL has no host');
        }

        // "packed" because browsers may pass self-attestation through even when asked
        // for none. No certificate is trusted or kept either way.
        return new WebAuthn('Edict', strtolower($host), ['none', 'packed'], true);
    }

    /**
     * The library accepts any origin ending in the host name, so "evil-edict.example"
     * would pass for "edict.example". We know the one origin that is allowed, and a
     * ceremony inside another site's frame is never ours.
     */
    private function assertOurOrigin(string $clientData): void
    {
        $data = json_decode($clientData, true);
        if (!is_array($data) || !is_string($data['origin'] ?? null) || strcasecmp($data['origin'], $this->config->appUrl) !== 0) {
            throw new PasskeyException('Wrong origin');
        }
        if (($data['crossOrigin'] ?? false) !== false) {
            throw new PasskeyException('Cross-origin ceremony');
        }
    }

    private function consumeChallenge(string $challenge): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare('DELETE FROM webauthn_used_challenge WHERE expires_at <= to_timestamp(?)')->execute([$now]);

        $insert = $this->pdo->prepare(
            'INSERT INTO webauthn_used_challenge (challenge_hash, expires_at) VALUES (?, to_timestamp(?)) ON CONFLICT DO NOTHING',
        );
        $insert->execute([hash('sha256', $challenge), $now + self::CHALLENGE_TTL]);
        if ($insert->rowCount() !== 1) {
            throw new PasskeyException('That challenge was already used');
        }
    }
}
