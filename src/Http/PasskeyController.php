<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\AdminAccount;
use Edict\Auth\LoginThrottle;
use Edict\Auth\PasskeyException;
use Edict\Auth\Passkeys;
use Edict\Auth\Session;
use Edict\Auth\SessionStore;
use Edict\Config;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Passkey login and management. The browser's WebAuthn API needs JavaScript, so
 * these endpoints speak JSON (public/assets/admin/passkey.js).
 */
final readonly class PasskeyController
{
    public function __construct(
        private Environment $twig,
        private Passkeys $passkeys,
        private AdminAccount $admin,
        private SessionStore $sessions,
        private SessionCookie $sessionCookie,
        private ChallengeCookie $challengeCookie,
        private LoginThrottle $throttle,
        private Config $config,
    ) {}

    public function loginOptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->throttle->isPasskeyBlocked(self::ip($request))) {
            return self::tooManyAttempts($response);
        }

        $created = $this->passkeys->assertionOptions();

        return self::json(
            $this->challengeCookie->set($response, ChallengeCookie::LOGIN, $created['challenge'], null),
            $created['options'],
        );
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = self::ip($request);
        if ($this->throttle->isPasskeyBlocked($ip)) {
            return self::tooManyAttempts($response);
        }

        $body = $request->getParsedBody();
        $challenge = $this->challengeCookie->read($request, ChallengeCookie::LOGIN, null);
        $response = $this->challengeCookie->clear($response);

        try {
            if ($challenge === null) {
                throw new PasskeyException('No valid challenge');
            }
            $userId = $this->passkeys->verifyLogin(
                self::binary($body, 'id'),
                self::binary($body, 'clientDataJSON'),
                self::binary($body, 'authenticatorData'),
                self::binary($body, 'signature'),
                $challenge,
            );
        } catch (PasskeyException $e) {
            $this->throttle->recordPasskeyFailure($ip);
            $this->log($e);

            return self::json($response->withStatus(401), ['error' => 'That passkey could not be verified.']);
        }

        // Always a new session, so a token planted before login never becomes privileged.
        $current = self::session($request);
        if ($current !== null) {
            $this->sessions->destroy($current);
        }
        $token = $this->sessions->create($userId, SessionStore::ADMIN_TTL);

        return self::json($this->sessionCookie->set($response, $token, SessionStore::ADMIN_TTL), ['redirect' => '/admin']);
    }

    public function manage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::requireSession($request);
        $response->getBody()->write($this->twig->render('admin/passkeys.html.twig', [
            'passkeys' => $this->passkeys->all($session->userId),
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session->csrfToken,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Adding a way in needs the password and authenticator code as well as a session:
     * a stolen session must not be able to leave a permanent key behind.
     */
    public function registerOptions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::requireSession($request);
        $username = $this->admin->usernameOf($session->userId);
        if ($username === null) {
            return self::json($response->withStatus(403), ['error' => 'This account has no password login.']);
        }

        $ip = self::ip($request);
        if ($this->throttle->isBlocked($ip, $username)) {
            return self::tooManyAttempts($response);
        }

        $body = $request->getParsedBody();
        $this->throttle->recordFailure($ip, $username);
        $verified = $this->admin->verify(
            $username,
            AdminAuthController::credential($body, 'password'),
            AdminAuthController::credential($body, 'code'),
        );
        if ($verified !== $session->userId) {
            return self::json($response->withStatus(401), ['error' => 'That password or code is not valid.']);
        }
        $this->throttle->clear($ip, $username);

        $created = $this->passkeys->creationOptions($session->userId, $session->displayName);

        return self::json(
            $this->challengeCookie->set($response, ChallengeCookie::REGISTER, $created['challenge'], $session->userId),
            $created['options'],
        );
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::requireSession($request);
        $body = $request->getParsedBody();
        $challenge = $this->challengeCookie->read($request, ChallengeCookie::REGISTER, $session->userId);
        $response = $this->challengeCookie->clear($response);

        try {
            if ($challenge === null) {
                throw new PasskeyException('No valid challenge');
            }
            $this->passkeys->register(
                $session->userId,
                AdminAuthController::credential($body, 'name'),
                self::binary($body, 'clientDataJSON'),
                self::binary($body, 'attestationObject'),
                $challenge,
            );
        } catch (PasskeyException $e) {
            $this->log($e);

            return self::json($response->withStatus(400), ['error' => 'That passkey could not be added.']);
        }

        return self::json($response, ['redirect' => '/admin/passkeys']);
    }

    /**
     * @param array<string, string> $args
     */
    public function remove(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? '';
        if (ctype_digit($id)) {
            $this->passkeys->remove(self::requireSession($request)->userId, (int) $id);
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/passkeys');
    }

    private function log(PasskeyException $e): void
    {
        if ($this->config->env !== 'test') {
            error_log('Passkey ceremony failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<mixed>|object|null $body
     */
    private static function binary(array|object|null $body, string $field): string
    {
        $value = is_array($body) ? ($body[$field] ?? null) : null;

        return is_string($value) ? Passkeys::decode($value) : '';
    }

    private static function tooManyAttempts(ResponseInterface $response): ResponseInterface
    {
        return self::json($response->withStatus(429), ['error' => 'Too many failed attempts. Please try again later.'])
            ->withHeader('Retry-After', (string) LoginThrottle::WINDOW);
    }

    private static function json(ResponseInterface $response, mixed $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function ip(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : 'unknown';
    }

    private static function session(ServerRequestInterface $request): ?Session
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        return $session instanceof Session ? $session : null;
    }

    private static function requireSession(ServerRequestInterface $request): Session
    {
        return self::session($request) ?? throw new \LogicException('Passkey management must sit behind RequireAdmin');
    }
}
