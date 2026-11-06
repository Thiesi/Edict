<?php

declare(strict_types=1);

namespace Edict\Tests;

use Edict\Auth\Passkeys;

/**
 * A software passkey: makes real ES256 signatures and "none" attestation, so
 * tests exercise the actual verification instead of a fake.
 */
final class SoftAuthenticator
{
    private const FLAG_PRESENT = 0x01;
    private const FLAG_VERIFIED = 0x04;
    private const FLAG_ATTESTED = 0x40;

    public string $credentialId;
    public int $counter = 0;
    public string $origin = 'http://localhost:8080';
    public string $rpId = 'localhost';
    public bool $verified = true;
    private \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Could not create a test key');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * @return array<string, string> the browser's registration fields
     */
    public function create(string $challenge): array
    {
        $details = openssl_pkey_get_details($this->key);
        if ($details === false) {
            throw new \RuntimeException('Could not read the test key');
        }

        // COSE EC2 key: kty 2, alg ES256, crv P-256, x, y.
        $cose = "\xA5\x01\x02\x03\x26\x20\x01"
            . "\x21\x58\x20" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . "\x22\x58\x20" . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $authData = hash('sha256', $this->rpId, true)
            . chr(self::FLAG_PRESENT | ($this->verified ? self::FLAG_VERIFIED : 0) | self::FLAG_ATTESTED)
            . pack('N', $this->counter)
            . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $cose;

        // CBOR map: fmt "none", attStmt {}, authData <bytes>.
        $attestation = "\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData\x59" . pack('n', strlen($authData)) . $authData;

        return [
            'clientDataJSON' => Passkeys::encode($this->clientData('webauthn.create', $challenge)),
            'attestationObject' => Passkeys::encode($attestation),
        ];
    }

    /**
     * @return array<string, string> the browser's login fields
     */
    public function get(string $challenge): array
    {
        $clientData = $this->clientData('webauthn.get', $challenge);
        $authData = hash('sha256', $this->rpId, true)
            . chr(self::FLAG_PRESENT | ($this->verified ? self::FLAG_VERIFIED : 0))
            . pack('N', $this->counter);

        $signature = '';
        if (!openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign');
        }

        return [
            'id' => Passkeys::encode($this->credentialId),
            'clientDataJSON' => Passkeys::encode($clientData),
            'authenticatorData' => Passkeys::encode($authData),
            'signature' => Passkeys::encode($signature),
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin], JSON_THROW_ON_ERROR);
    }
}
