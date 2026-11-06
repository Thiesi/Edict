<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\AdminAccount;
use Edict\Auth\Passkeys;
use Edict\Auth\SessionStore;
use Edict\Auth\Totp;
use Edict\Tests\DbTestCase;
use Edict\Tests\SoftAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

final class PasskeyTest extends DbTestCase
{
    private const PASSWORD = 'correct horse battery';
    private const SESSION = 'edict_session';
    private const CHALLENGE = 'edict_passkey';

    private string $secret;
    private SoftAuthenticator $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = $this->service(AdminAccount::class)->create('sovereign', self::PASSWORD);
        $this->device = new SoftAuthenticator();
    }

    private function code(): string
    {
        return Totp::code($this->secret, intdiv($this->clock->now(), 30));
    }

    /**
     * @return array<string, string> cookies of a signed-in admin
     */
    private function adminSession(): array
    {
        $response = $this->post('/admin/login', ['username' => 'sovereign', 'password' => self::PASSWORD, 'code' => $this->code()]);
        self::assertSame(303, $response->getStatusCode());
        $this->clock->advance(31); // Each code works once.

        return [self::SESSION => self::cookie($response, self::SESSION)];
    }

    private static function cookie(ResponseInterface $response, string $name): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . $name . '=([^;]+)/', $header, $match) === 1) {
                return $match[1];
            }
        }
        self::fail("Cookie $name was not set");
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function challengeIn(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $publicKey = self::body($response)['publicKey'] ?? null;
        self::assertIsArray($publicKey);
        $challenge = $publicKey['challenge'] ?? null;
        self::assertIsString($challenge);

        return $challenge;
    }

    /**
     * @param array<string, string> $cookies
     *
     * @return array<string, string> headers with the session's CSRF token
     */
    private function csrf(array $cookies): array
    {
        $session = $this->service(SessionStore::class)->load($cookies[self::SESSION]);
        self::assertNotNull($session);

        return ['X-CSRF-Token' => $session->csrfToken];
    }

    /**
     * @param array<string, string> $cookies
     */
    private function askToRegister(array $cookies, ?string $password = null, ?string $code = null, string $name = 'Laptop'): ResponseInterface
    {
        return $this->post(
            '/admin/passkeys/options',
            ['name' => $name, 'password' => $password ?? self::PASSWORD, 'code' => $code ?? $this->code()],
            $cookies,
            $this->csrf($cookies),
        );
    }

    /**
     * @param array<string, string> $cookies
     */
    private function register(array $cookies, string $name = 'Laptop'): ResponseInterface
    {
        $options = $this->askToRegister($cookies);
        $challenge = self::challengeIn($options);

        return $this->post(
            '/admin/passkeys',
            ['name' => $name] + $this->device->create($challenge),
            $cookies + [self::CHALLENGE => self::cookie($options, self::CHALLENGE)],
            $this->csrf($cookies),
        );
    }

    private function registered(): void
    {
        $response = $this->register($this->adminSession());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->clock->advance(31);
    }

    /**
     * @return array{challenge: string, cookies: array<string, string>}
     */
    private function askToLogIn(): array
    {
        $options = $this->post('/admin/passkey/options');

        return [
            'challenge' => self::challengeIn($options),
            'cookies' => [self::CHALLENGE => self::cookie($options, self::CHALLENGE)],
        ];
    }

    private function logIn(): ResponseInterface
    {
        $asked = $this->askToLogIn();

        return $this->post('/admin/passkey/login', $this->device->get($asked['challenge']), $asked['cookies']);
    }

    private function countRows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    public function testRegisterThenLogIn(): void
    {
        $this->registered();
        self::assertSame(1, $this->countRows('passkey'));

        $response = $this->logIn();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('/admin', self::body($response)['redirect']);
        $cookies = [self::SESSION => self::cookie($response, self::SESSION)];
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode());
    }

    public function testOptionsAskForDiscoverableVerifiedPasskeys(): void
    {
        $options = self::body($this->askToRegister($this->adminSession()))['publicKey'] ?? null;
        self::assertIsArray($options);
        self::assertIsArray($options['authenticatorSelection']);
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertIsArray($options['rp']);
        self::assertSame('localhost', $options['rp']['id']);
        self::assertSame('none', $options['attestation']);
    }

    public function testRegisteredKeysAreExcludedFromTheNextRegistration(): void
    {
        $this->registered();

        $options = self::body($this->askToRegister($this->adminSession()))['publicKey'] ?? null;

        self::assertIsArray($options);
        self::assertIsArray($options['excludeCredentials']);
        self::assertIsArray($options['excludeCredentials'][0]);
        self::assertSame(Passkeys::encode($this->device->credentialId), $options['excludeCredentials'][0]['id']);
    }

    public function testStartingALoginWritesNothing(): void
    {
        $this->askToLogIn();

        self::assertSame(0, $this->countRows('webauthn_used_challenge'));
        self::assertSame(0, $this->countRows('login_attempt'));
    }

    public function testRegistrationNeedsAdminSession(): void
    {
        self::assertSame(303, $this->post('/admin/passkeys/options', ['password' => self::PASSWORD, 'code' => $this->code()])->getStatusCode());

        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('reader') RETURNING id");
        self::assertNotFalse($insert);
        $token = $this->service(SessionStore::class)->create((int) $insert->fetchColumn(), 3600);
        $cookies = [self::SESSION => $token];

        self::assertSame(403, $this->post('/admin/passkeys/options', [], $cookies, $this->csrf($cookies))->getStatusCode());
    }

    public function testRegistrationNeedsCsrfToken(): void
    {
        $response = $this->post('/admin/passkeys/options', ['password' => self::PASSWORD, 'code' => $this->code()], $this->adminSession());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testRegistrationNeedsPasswordAndCode(): void
    {
        $cookies = $this->adminSession();

        self::assertSame(401, $this->askToRegister($cookies, password: 'wrong wrong wrong')->getStatusCode());
        self::assertSame(401, $this->askToRegister($cookies, code: '000000')->getStatusCode());
        self::assertSame(401, $this->askToRegister($cookies, password: "a\0b")->getStatusCode());
    }

    public function testFailedStepUpIsThrottled(): void
    {
        $cookies = $this->adminSession();
        for ($i = 0; $i < 5; ++$i) {
            $this->askToRegister($cookies, password: 'wrong wrong wrong');
        }

        self::assertSame(429, $this->askToRegister($cookies)->getStatusCode());
    }

    public function testRegistrationNeedsItsOwnChallenge(): void
    {
        $cookies = $this->adminSession();
        $login = $this->askToLogIn();

        $response = $this->post(
            '/admin/passkeys',
            ['name' => 'Laptop'] + $this->device->create($login['challenge']),
            $cookies + $login['cookies'],
            $this->csrf($cookies),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->countRows('passkey'));
    }

    public function testRegistrationRefusesWrongOriginAndMissingVerification(): void
    {
        $this->device->origin = 'http://evil-localhost:8080';
        $wrongOrigin = $this->register($this->adminSession());
        self::assertSame(400, $wrongOrigin->getStatusCode());

        $this->clock->advance(31);
        $this->device->origin = 'http://localhost:8080';
        $this->device->verified = false;
        self::assertSame(400, $this->register($this->adminSession())->getStatusCode());

        self::assertSame(0, $this->countRows('passkey'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'only invisible' => ["\u{200B}"];
        yield 'too long' => [str_repeat('x', 65)];
    }

    #[DataProvider('badNames')]
    public function testRegistrationRefusesBadNames(string $name): void
    {
        self::assertSame(400, $this->register($this->adminSession(), $name)->getStatusCode());
    }

    #[DataProvider('badNames')]
    public function testBadNameIsRefusedBeforeAskingTheAuthenticator(string $name): void
    {
        $response = $this->askToRegister($this->adminSession(), name: $name);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('publicKey', (string) $response->getBody());
        self::assertSame(0, $this->countRows('login_attempt'));
    }

    public function testSelfAttestationIsAccepted(): void
    {
        $this->device->selfAttestation = true;

        $this->registered();

        self::assertSame(200, $this->logIn()->getStatusCode());
    }

    public function testRegistrationChallengeDiesWithItsSession(): void
    {
        $first = $this->adminSession();
        $second = $this->adminSession();
        $options = $this->askToRegister($first);
        $fields = ['name' => 'Laptop'] + $this->device->create(self::challengeIn($options));
        $challenge = [self::CHALLENGE => self::cookie($options, self::CHALLENGE)];

        $response = $this->post('/admin/passkeys', $fields, $second + $challenge, $this->csrf($second));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->countRows('passkey'));
    }

    public function testLibraryErrorsOfAnyTypeAreFailedLogins(): void
    {
        $this->registered();
        $asked = $this->askToLogIn();
        $fields = $this->device->get($asked['challenge']);

        // A challenge that isn't a string raises a TypeError inside the library.
        $fields['clientDataJSON'] = Passkeys::encode((string) json_encode([
            'type' => 'webauthn.get',
            'challenge' => [],
            'origin' => self::ORIGIN,
        ]));

        self::assertSame(401, $this->post('/admin/passkey/login', $fields, $asked['cookies'])->getStatusCode());
        self::assertSame(1, $this->countRows('login_attempt'));
    }

    public function testMalformedLoginBodiesAreFailedLogins(): void
    {
        $this->registered();

        foreach ([
            [],
            ['id' => 'not base64!', 'clientDataJSON' => '%%%', 'authenticatorData' => '', 'signature' => ''],
            ['id' => Passkeys::encode($this->device->credentialId), 'clientDataJSON' => Passkeys::encode('not json')],
        ] as $body) {
            $asked = $this->askToLogIn();
            self::assertSame(401, $this->post('/admin/passkey/login', $body, $asked['cookies'])->getStatusCode());
        }
    }

    public function testSyncedPasskeyWithAStaleCounterKeepsWorking(): void
    {
        $this->device->synced = true;
        $this->device->counter = 5;
        $this->registered();

        $this->device->counter = 0;

        self::assertSame(200, $this->logIn()->getStatusCode());
    }

    public function testLoginChallengeIsSingleUse(): void
    {
        $this->registered();
        $asked = $this->askToLogIn();
        $fields = $this->device->get($asked['challenge']);

        self::assertSame(200, $this->post('/admin/passkey/login', $fields, $asked['cookies'])->getStatusCode());
        self::assertSame(401, $this->post('/admin/passkey/login', $fields, $asked['cookies'])->getStatusCode());
    }

    public function testLoginChallengeExpires(): void
    {
        $this->registered();
        $asked = $this->askToLogIn();
        $this->clock->advance(Passkeys::CHALLENGE_TTL + 1);

        $response = $this->post('/admin/passkey/login', $this->device->get($asked['challenge']), $asked['cookies']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testExpiredChallengesArePurged(): void
    {
        $this->registered();
        self::assertSame(1, $this->countRows('webauthn_used_challenge'));

        $this->clock->advance(Passkeys::CHALLENGE_TTL + 1);
        self::assertSame(200, $this->logIn()->getStatusCode());

        self::assertSame(1, $this->countRows('webauthn_used_challenge'));
    }

    public function testLoginNeedsTheCookieThatIssuedTheChallenge(): void
    {
        $this->registered();
        $asked = $this->askToLogIn();
        $other = $this->askToLogIn();

        self::assertSame(401, $this->post('/admin/passkey/login', $this->device->get($asked['challenge']), [])->getStatusCode());
        self::assertSame(401, $this->post('/admin/passkey/login', $this->device->get($asked['challenge']), $other['cookies'])->getStatusCode());
    }

    public function testLoginRefusesARegistrationChallenge(): void
    {
        $this->registered();
        $options = $this->askToRegister($this->adminSession());
        $challenge = self::challengeIn($options);

        $response = $this->post(
            '/admin/passkey/login',
            $this->device->get($challenge),
            [self::CHALLENGE => self::cookie($options, self::CHALLENGE)],
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginRefusesWrongOriginRpAndMissingVerification(): void
    {
        $this->registered();

        foreach ([
            static function (SoftAuthenticator $d): void {
                $d->origin = 'http://evil-localhost:8080';
            },
            static function (SoftAuthenticator $d): void {
                $d->origin = 'http://localhost:9999';
            },
            static function (SoftAuthenticator $d): void {
                $d->rpId = 'evil.example';
            },
            static function (SoftAuthenticator $d): void {
                $d->verified = false;
            },
        ] as $tamper) {
            $device = clone $this->device;
            $tamper($device);
            $asked = $this->askToLogIn();
            self::assertSame(401, $this->post('/admin/passkey/login', $device->get($asked['challenge']), $asked['cookies'])->getStatusCode());
        }
    }

    public function testLoginRefusesABadSignatureAndAnUnknownKey(): void
    {
        $this->registered();

        $asked = $this->askToLogIn();
        $forged = (new SoftAuthenticator())->get($asked['challenge']);
        $forged['id'] = Passkeys::encode($this->device->credentialId);
        self::assertSame(401, $this->post('/admin/passkey/login', $forged, $asked['cookies'])->getStatusCode());

        $asked = $this->askToLogIn();
        $stranger = (new SoftAuthenticator())->get($asked['challenge']);
        self::assertSame(401, $this->post('/admin/passkey/login', $stranger, $asked['cookies'])->getStatusCode());
    }

    public function testCounterGoingBackwardsIsRefused(): void
    {
        $this->device->counter = 5;
        $this->registered();

        $this->device->counter = 6;
        self::assertSame(200, $this->logIn()->getStatusCode());

        $this->device->counter = 6;
        self::assertSame(401, $this->logIn()->getStatusCode());
    }

    public function testPasskeysWithoutACounterKeepWorking(): void
    {
        $this->registered();

        $first = $this->logIn();
        $second = $this->logIn();

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function testFailuresAreThrottledWithoutTouchingPasswordLogin(): void
    {
        $this->registered();
        for ($i = 0; $i < 20; ++$i) {
            $this->post('/admin/passkey/login', [], []);
        }

        self::assertSame(429, $this->post('/admin/passkey/options')->getStatusCode());
        self::assertSame(429, $this->post('/admin/passkey/login', [], [])->getStatusCode());

        $password = $this->post('/admin/login', ['username' => 'sovereign', 'password' => self::PASSWORD, 'code' => $this->code()]);
        self::assertSame(303, $password->getStatusCode());
    }

    public function testLoginReplacesExistingSession(): void
    {
        $this->registered();
        $old = $this->adminSession();

        $asked = $this->askToLogIn();
        $response = $this->post('/admin/passkey/login', $this->device->get($asked['challenge']), $asked['cookies'] + $old, $this->csrf($old));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(303, $this->get('/admin', $old)->getStatusCode());
    }

    public function testManagePageListsAndRemoves(): void
    {
        $cookies = $this->adminSession();
        self::assertSame(200, $this->register($cookies, '<b>Laptop</b>')->getStatusCode());

        $page = $this->get('/admin/passkeys', $cookies);
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $body = (string) $page->getBody();
        self::assertStringContainsString('&lt;b&gt;Laptop', $body);
        self::assertStringContainsString('/assets/admin/passkey.js', $body);

        self::assertSame(403, $this->post('/admin/passkeys/1/remove', [], $cookies)->getStatusCode());
        self::assertSame(1, $this->countRows('passkey'));

        $removed = $this->post('/admin/passkeys/1/remove', [], $cookies, $this->csrf($cookies));
        self::assertSame('/admin/passkeys', $removed->getHeaderLine('Location'));
        self::assertSame(0, $this->countRows('passkey'));

        self::assertSame(401, $this->logIn()->getStatusCode());
    }

    public function testResetRemovesPasskeys(): void
    {
        $this->registered();

        $this->service(AdminAccount::class)->reset('a brand new passphrase');

        self::assertSame(0, $this->countRows('passkey'));
    }

    public function testAdminLoginPageOffersPasskey(): void
    {
        $body = (string) $this->get('/admin/login')->getBody();

        self::assertStringContainsString('id="passkey-login"', $body);
        self::assertStringContainsString('/assets/admin/passkey.js', $body);
    }
}
