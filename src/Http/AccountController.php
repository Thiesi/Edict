<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Auth\Session;
use Edict\Auth\SessionStore;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Edict\Mail\SecurityNotice;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * The signed-in person's own account. All routes sit behind RequireLogin.
 */
final readonly class AccountController
{
    public function __construct(
        private Environment $twig,
        private Accounts $accounts,
        private ProviderRegistry $providers,
        private SessionStore $sessions,
        private SessionCookie $cookie,
        private LastProviderCookie $lastProvider,
        private AdminAccount $admin,
        private StepUp $stepUp,
        private SecurityNotice $notices,
    ) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        $identities = $this->accounts->identities($session->userId);

        $linkable = $session->isAdmin ? [] : $this->providers->all();
        foreach ($identities as $identity) {
            unset($linkable[$identity['provider']]);
        }
        $query = $request->getQueryParams();
        $last = is_string($query['last'] ?? null) && isset($linkable[$query['last']]) ? $linkable[$query['last']] : null;

        $response->getBody()->write($this->twig->render('account/index.html.twig', [
            'notice' => Notices::fromRequest($request),
            'display_name' => $session->displayName,
            'is_admin' => $session->isAdmin,
            'max_name_length' => Accounts::MAX_NAME_LENGTH,
            'identities' => $identities,
            'linkable' => $linkable,
            'welcome' => ($query['notice'] ?? null) === 'welcome',
            'last_provider' => $last,
            'can_remove' => !$session->isAdmin && count($identities) > 1,
            'providers' => $this->providers->all(),
            'admin_email' => $session->isAdmin ? $this->admin->email() : null,
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session->csrfToken,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function rename(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $renamed = $this->accounts->rename(self::session($request)->userId, self::field($request, 'display_name'));

        return self::redirect($response, '/account?notice=' . ($renamed ? 'renamed' : 'invalid-name'));
    }

    /**
     * @param array<string, string> $args
     */
    public function unlink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = self::session($request);
        if ($session->isAdmin) {
            return self::redirect($response, '/account?notice=admin-cli');
        }

        return self::redirect($response, '/account?notice=' . $this->accounts->unlink($session->userId, $args['provider'] ?? ''));
    }

    /**
     * The admin's notification address. Confirmed like a login change: a stolen
     * session mustn't be able to redirect the security notices.
     */
    public function adminEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        if (!$session->isAdmin) {
            return self::redirect($response, '/account');
        }
        $confirmed = $this->stepUp->confirm($request, $session);
        if ($confirmed !== 'ok') {
            return self::redirect($response, '/account?notice=' . $confirmed);
        }
        $old = $this->admin->email();
        try {
            $new = $this->admin->setEmail(self::field($request, 'email'));
        } catch (\InvalidArgumentException) {
            return self::redirect($response, '/account?notice=admin-email-invalid');
        }
        if ($new !== $old) {
            // The old address hears about it too, in case it wasn't the owner.
            $this->notices->record($new === null ? 'The admin notification address was removed' : "The admin notification address was changed to $new", $old);
        }

        return self::redirect($response, '/account?notice=admin-email-saved');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->sessions->destroy(self::session($request));

        return self::redirect($this->cookie->clear($response), '/');
    }

    public function logoutEverywhere(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->sessions->destroyAllForUser(self::session($request)->userId);

        return self::redirect($this->cookie->clear($response), '/');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        if ($session->isAdmin) {
            return self::redirect($response, '/account');
        }
        if (self::field($request, 'confirm') !== 'yes') {
            return self::redirect($response, '/account?notice=confirm-delete');
        }

        $this->accounts->delete($session->userId);

        return self::redirect($this->lastProvider->clear($this->cookie->clear($response)), '/');
    }

    private static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        if (!$session instanceof Session) {
            throw new \LogicException('Account routes must sit behind RequireLogin');
        }

        return $session;
    }

    private static function field(ServerRequestInterface $request, string $name): string
    {
        $body = $request->getParsedBody();

        return is_array($body) && is_string($body[$name] ?? null) ? $body[$name] : '';
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $location);
    }
}
