<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Body;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class CommentFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    private int $article;

    protected function setUp(): void
    {
        parent::setUp();
        $stmt = $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', 'talk', 'en', 'Talk', ?, 'published', to_timestamp(?), now(), now()) RETURNING id",
        );
        $stmt->execute([Body::EMPTY, $this->clock->now() - 86400]);
        $this->article = (int) $stmt->fetchColumn();
    }

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function reader(string $name = 'Reader'): array
    {
        $insert = $this->pdo->prepare('INSERT INTO app_user (display_name) VALUES (?) RETURNING id');
        $insert->execute([$name]);

        return $this->signIn((int) $insert->fetchColumn());
    }

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function admin(): array
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);

        return $this->signIn((int) $id->fetchColumn());
    }

    /**
     * @return array{id: int, cookies: array<string, string>, csrf: string}
     */
    private function signIn(int $userId): array
    {
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create($userId, 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);

        return ['id' => $userId, 'cookies' => [self::SESSION => $token], 'csrf' => $session->csrfToken];
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string} $who
     */
    private function comment(array $who, string $body, ?int $parent = null): ResponseInterface
    {
        $fields = ['body' => $body, '_csrf' => $who['csrf']] + ($parent === null ? [] : ['parent' => (string) $parent]);

        return $this->post("/comments/{$this->article}", $fields, $who['cookies']);
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string} $who
     */
    private function posted(array $who, string $body, ?int $parent = null): int
    {
        $response = $this->comment($who, $body, $parent);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, preg_match('~#comment-([0-9]+)$~', $response->getHeaderLine('Location'), $match));

        return (int) ($match[1] ?? 0);
    }

    public function testOnlyTheAdminSeesTheWayIntoTheAdminAndTheEditor(): void
    {
        $edit = '<a href="/admin/articles/' . $this->article . '">Edit this article</a>';
        $menu = '<a href="/admin">Admin</a>';

        foreach ([null, $this->reader()] as $who) {
            $html = $this->page($who);
            self::assertStringNotContainsString($edit, $html);
            self::assertStringNotContainsString($menu, $html);
        }

        $admin = $this->admin();
        $html = $this->page($admin);
        self::assertStringContainsString($edit, $html);
        self::assertStringContainsString($menu, $html);
        self::assertStringContainsString($menu, (string) $this->get('/', $admin['cookies'])->getBody(), 'on every public page');
        self::assertStringNotContainsString('Edit this', (string) $this->get('/', $admin['cookies'])->getBody(), 'only where there is something to edit');
        self::assertStringContainsString($menu, (string) $this->get('/account', $admin['cookies'])->getBody());
        self::assertStringContainsString('<a href="/account">Account</a>', (string) $this->get('/admin', $admin['cookies'])->getBody(), 'and back from the admin');
        self::assertStringNotContainsString('Edit this', (string) $this->get('/admin/articles/' . $this->article . '/preview', $admin['cookies'])->getBody(), 'the preview has its own way back');
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string}|null $who
     */
    private function page(?array $who = null): string
    {
        return (string) $this->get('/talk', $who === null ? [] : $who['cookies'])->getBody();
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string} $admin
     * @param array<string, string> $fields
     */
    private function moderate(array $admin, int $id, string $action, array $fields = []): ResponseInterface
    {
        return $this->post("/admin/comments/$id/$action", $fields + ['_csrf' => $admin['csrf']], $admin['cookies']);
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string} $admin
     * @param array<string, string> $rules
     */
    private function rules(array $admin, array $rules): void
    {
        $response = $this->post('/admin/comments/rules', $rules + ['comment_default' => 'open', 'comment_auto_close_days' => '0', 'comment_words' => '', 'comment_word_action' => 'hold', '_csrf' => $admin['csrf']], $admin['cookies']);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testVisitorsAreAskedToLogIn(): void
    {
        $page = $this->page();
        self::assertStringContainsString('0 comments', $page);
        self::assertStringContainsString('href="/login?next=%2Ftalk%23comments">Log in to comment', $page);

        $response = $this->post("/comments/{$this->article}", ['body' => 'Hi']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?next=%2Ftalk%23comments', $response->getHeaderLine('Location'));
    }

    public function testAReaderCommentsAndRepliesNest(): void
    {
        $alice = $this->reader('Alice');
        $bob = $this->reader('Bob');

        $first = $this->posted($alice, "Nice <b>post</b>!\nSee https://example.org/a.");
        $reply = $this->posted($bob, 'Agreed.', $first);

        $page = $this->page();
        self::assertStringContainsString('2 comments', $page);
        self::assertStringContainsString('Nice &lt;b&gt;post&lt;/b&gt;!<br>', $page);
        self::assertStringContainsString('<a href="https://example.org/a" rel="nofollow ugc noopener">https://example.org/a</a>.', $page);
        self::assertMatchesRegularExpression('~id="comment-' . $first . '".*<details class="comment-replies" open>.*id="comment-' . $reply . '"~s', $page);
        self::assertStringNotContainsString('Remove', $page, 'readers get no moderation');
    }

    public function testReplyingKeepsAnswersAtTheIndentCapLabelled(): void
    {
        $reader = $this->reader('Deep');
        $parent = null;
        for ($i = 0; $i < 7; ++$i) {
            $parent = $this->posted($reader, "Level $i", $parent);
            $this->clock->advance(150); // Under the rate limit.
        }

        $page = $this->page();
        self::assertStringContainsString('class="comment depth-5"', $page, 'depth stops at five for the layout');
        self::assertStringContainsString('↳ in reply to Deep', $page);
    }

    public function testARefusedCommentKeepsItsText(): void
    {
        $reader = $this->reader();
        $response = $this->comment($reader, str_repeat('x', 5001));

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('role="alert">A comment can have up to 5000 characters.', $html);
        self::assertStringContainsString(str_repeat('x', 5001) . '</textarea>', $html);
        self::assertSame(422, $this->comment($reader, '   ')->getStatusCode());
    }

    public function testCommentingNeedsTheToken(): void
    {
        $reader = $this->reader();

        self::assertSame(403, $this->post("/comments/{$this->article}", ['body' => 'Hi'], $reader['cookies'])->getStatusCode());
    }

    public function testHeldCommentsWaitForTheSovereign(): void
    {
        $admin = $this->admin();
        $this->rules($admin, ['comment_default' => 'hold']);
        $alice = $this->reader('Alice');
        $id = $this->posted($alice, 'Let me in');

        self::assertStringNotContainsString('Let me in', $this->page(), 'others don’t see it');
        self::assertStringContainsString('waiting for approval, only you see it', $this->page($alice));
        self::assertStringContainsString('1 comment waits for approval', (string) $this->get('/admin', $admin['cookies'])->getBody());
        self::assertStringContainsString('Let me in', (string) $this->get('/admin/comments', $admin['cookies'])->getBody());
        // The admin's menu says so on every page, not only on the dashboard.
        self::assertStringContainsString('<span class="admin-count">', (string) $this->get('/admin/tags', $admin['cookies'])->getBody());

        self::assertSame(303, $this->moderate($admin, $id, 'approve')->getStatusCode());
        self::assertStringContainsString('Let me in', $this->page());
        self::assertStringNotContainsString('<span class="admin-count">', (string) $this->get('/admin/tags', $admin['cookies'])->getBody());

        $this->moderate($admin, $id, 'trust');
        $this->posted($alice, 'Straight through now');
        self::assertStringContainsString('Straight through now', $this->page(), 'an approved author skips the queue');
    }

    public function testTheWordFilterHoldsOrRefusesWholeWords(): void
    {
        $admin = $this->admin();
        $reader = $this->reader();
        $this->rules($admin, ['comment_words' => "spam\nbuy now"]);

        $this->posted($reader, 'This is SPAM, really');
        $this->posted($reader, 'A spammer was spamming in the classroom.');
        $page = $this->page();
        self::assertStringNotContainsString('really', $page, 'held');
        self::assertStringContainsString('spamming in the classroom', $page, 'only whole words count');

        $this->rules($admin, ['comment_words' => 'buy now', 'comment_word_action' => 'reject']);
        $response = $this->comment($reader, 'Buy   now? No: buy now!');
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('words this site doesn’t accept', (string) $response->getBody());
    }

    public function testClosedAndAutoClosedArticlesTakeNoComments(): void
    {
        $admin = $this->admin();
        $reader = $this->reader();

        $this->post("/admin/articles/{$this->article}/comments", ['mode' => 'closed', '_csrf' => $admin['csrf']], $admin['cookies']);
        self::assertSame(422, $this->comment($reader, 'Hello?')->getStatusCode());
        self::assertStringContainsString('Comments are closed.', $this->page($reader));

        $this->post("/admin/articles/{$this->article}/comments", ['mode' => 'site', '_csrf' => $admin['csrf']], $admin['cookies']);
        $this->rules($admin, ['comment_auto_close_days' => '30']);
        $this->posted($reader, 'Still open');
        $this->clock->advance(31 * 86400);
        self::assertSame(422, $this->comment($reader, 'Too late')->getStatusCode());

        $this->post("/admin/articles/{$this->article}/comments", ['mode' => 'open', '_csrf' => $admin['csrf']], $admin['cookies']);
        $this->posted($reader, 'Reopened by hand');
    }

    public function testBannedReadersCannotComment(): void
    {
        $admin = $this->admin();
        $reader = $this->reader();
        $id = $this->posted($reader, 'First');

        self::assertSame(303, $this->moderate($admin, $id, 'ban', ['days' => '7'])->getStatusCode());

        self::assertSame(303, $this->comment($reader, 'Again')->getStatusCode(), 'their session ended: back to sign-in');
        $again = $this->signIn($reader['id']);
        self::assertSame(422, $this->comment($again, 'Again')->getStatusCode());
        self::assertStringContainsString('Reader', (string) $this->get('/admin/comments', $admin['cookies'])->getBody());

        $this->post("/admin/users/{$reader['id']}/unban", ['_csrf' => $admin['csrf']], $admin['cookies']);
        $this->posted($again, 'Back');
    }

    public function testTooManyCommentsAtOnceAreSlowedDown(): void
    {
        $reader = $this->reader();
        for ($i = 0; $i < 5; ++$i) {
            $this->posted($reader, "Comment $i");
        }

        $response = $this->comment($reader, 'One more');
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Please wait a few minutes', (string) $response->getBody());
        $this->clock->advance(601);
        $this->posted($reader, 'Later');
    }

    public function testRemovingOneKeepsItsRepliesAndTheThreadGoesWhole(): void
    {
        $admin = $this->admin();
        $alice = $this->reader('Alice');
        $root = $this->posted($alice, 'Root comment');
        $this->posted($alice, 'A reply', $root);
        $leaf = $this->posted($alice, 'Lonely leaf');

        $this->moderate($admin, $root, 'delete');
        $this->moderate($admin, $leaf, 'delete');
        $page = $this->page();
        self::assertStringContainsString('[removed]', $page);
        self::assertStringNotContainsString('Root comment', $page);
        self::assertStringContainsString('A reply', $page);
        self::assertStringNotContainsString('Lonely leaf', $page);
        self::assertSame(1, substr_count($page, '[removed]'), 'a removed comment without replies is simply gone');

        $this->moderate($admin, $root, 'delete-thread');
        self::assertStringNotContainsString('A reply', $this->page());
        self::assertStringNotContainsString('[removed]', $this->page());
    }

    public function testTheSovereignEditsAndPins(): void
    {
        $admin = $this->admin();
        $alice = $this->reader('Alice');
        $older = $this->posted($alice, 'Older');
        $this->clock->advance(10);
        $this->posted($alice, 'Newer');

        $this->moderate($admin, $older, 'edit', ['body' => 'Older, corrected']);
        $this->moderate($admin, $older, 'pin');

        $page = $this->page();
        self::assertStringContainsString('Older, corrected', $page);
        self::assertStringContainsString('edited', $page);
        self::assertLessThan(strpos($page, 'Newer'), strpos($page, 'Older, corrected'), 'pinned first');
        $self = (string) $this->get('/talk?edit=' . $older, $admin['cookies'])->getBody();
        self::assertStringContainsString('<textarea id="edit-' . $older . '"', $self);
    }

    public function testReadersCannotModerate(): void
    {
        $alice = $this->reader('Alice');
        $id = $this->posted($alice, 'Mine');

        self::assertSame(403, $this->post("/admin/comments/$id/delete", ['_csrf' => $alice['csrf']], $alice['cookies'])->getStatusCode());
        self::assertSame(403, $this->post('/admin/comments/rules', ['_csrf' => $alice['csrf']], $alice['cookies'])->getStatusCode());
        self::assertStringContainsString('Mine', $this->page());
    }

    public function testADeletedAccountsCommentsStayUnderNoName(): void
    {
        $alice = $this->reader('Alice');
        $this->posted($alice, 'Before I go');

        $this->service(Accounts::class)->delete($alice['id']);

        $page = $this->page();
        self::assertStringContainsString('Before I go', $page);
        self::assertStringContainsString('[deleted user]', $page);
        self::assertStringNotContainsString('Alice', $page);
    }

    private function state(int $comment): string
    {
        $stmt = $this->pdo->prepare('SELECT state FROM comment WHERE id = ?');
        $stmt->execute([$comment]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param array{id: int, cookies: array<string, string>, csrf: string}|null $who
     */
    private function deleteOwn(?array $who, int $comment, bool $withToken = true): ResponseInterface
    {
        return $this->post("/account/comments/$comment/delete", $who !== null && $withToken ? ['_csrf' => $who['csrf']] : [], $who === null ? [] : $who['cookies']);
    }

    public function testReadersDeleteTheirOwnCommentsAndRepliesStay(): void
    {
        $alice = $this->reader('Alice');
        $bob = $this->reader('Bob');
        $root = $this->posted($alice, 'I take this back');
        $reply = $this->posted($bob, 'Too late, I saw it', $root);

        $form = '<form method="post" action="/account/comments/' . $root . '/delete">';
        $page = $this->page($alice);
        self::assertStringContainsString($form, $page);
        self::assertStringContainsString('<input type="hidden" name="_csrf" value="' . $alice['csrf'] . '">', $page);
        self::assertMatchesRegularExpression('~Delete<span class="visually-hidden"> your comment from [^<]+</span></button>~', $page);
        self::assertStringNotContainsString('/account/comments/' . $reply . '/delete', $page, 'only on their own');
        self::assertStringNotContainsString($form, $this->page($bob));
        self::assertStringNotContainsString($form, $this->page());

        $response = $this->deleteOwn($alice, $root);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/talk?done=comment-deleted#comments', $response->getHeaderLine('Location'));
        self::assertSame('removed', $this->state($root));
        self::assertSame('visible', $this->state($reply));

        $page = $this->page();
        self::assertStringContainsString('[removed]', $page, 'as the admin’s “Remove only this”');
        self::assertStringNotContainsString('I take this back', $page);
        self::assertStringContainsString('Too late, I saw it', $page);
        self::assertStringContainsString('role="status">Your comment was deleted.</p>', (string) $this->get('/talk?done=comment-deleted', $alice['cookies'])->getBody());
        self::assertStringNotContainsString('Your comment was deleted.', (string) $this->get('/talk?done=comment-deleted')->getBody());
        self::assertStringNotContainsString($form, $this->page($alice), 'nothing left to delete');
    }

    public function testNobodyDeletesSomeoneElsesComment(): void
    {
        $alice = $this->reader('Alice');
        $bob = $this->reader('Bob');
        $id = $this->posted($alice, 'Mine alone');

        self::assertSame(404, $this->deleteOwn($bob, $id)->getStatusCode(), 'not 403: it doesn’t confirm the comment exists');
        self::assertSame(404, $this->deleteOwn($bob, $id + 1000)->getStatusCode());
        $admin = $this->admin();
        self::assertSame(404, $this->deleteOwn($admin, $id)->getStatusCode(), 'the admin has the Remove tools for that');

        $signedOut = $this->deleteOwn(null, $id);
        self::assertSame(303, $signedOut->getStatusCode());
        self::assertSame('/login', $signedOut->getHeaderLine('Location'));
        self::assertSame(403, $this->deleteOwn($alice, $id, false)->getStatusCode(), 'without the token');
        self::assertSame('visible', $this->state($id));

        $page = $this->page($admin);
        self::assertStringNotContainsString('/account/comments/', $page, 'the admin sees Remove, not Delete');
        self::assertStringContainsString('>Remove</button>', $page);
    }

    public function testABannedReaderMayStillDeleteTheirComments(): void
    {
        $admin = $this->admin();
        $alice = $this->reader('Alice');
        $id = $this->posted($alice, 'Regrettable');
        $this->moderate($admin, $id, 'ban', ['days' => 'forever']);

        $again = $this->signIn($alice['id']);
        self::assertStringContainsString('/account/comments/' . $id . '/delete', $this->page($again));
        self::assertSame(303, $this->deleteOwn($again, $id)->getStatusCode());
        self::assertSame('removed', $this->state($id));
    }

    private function deletedAt(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT deleted_at FROM app_user WHERE id = ?');
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function countFor(string $table, int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT count(*) FROM $table WHERE user_id = ?");
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    public function testTheAdminDeletesAReaderAccountOnlyWhenConfirmed(): void
    {
        $admin = $this->admin();
        $alice = $this->reader('Alice');
        $id = $this->posted($alice, 'Words that stay');
        $this->pdo->prepare("INSERT INTO user_identity (provider, subject, user_id) VALUES ('google', 'alice-sub', ?)")->execute([$alice['id']]);
        $this->pdo->prepare('INSERT INTO vote (article_id, user_id, created_at) VALUES (?, ?, now())')->execute([$this->article, $alice['id']]);

        $form = '<form method="post" action="/admin/comments/' . $id . '/delete-account">';
        $page = $this->page($admin);
        self::assertStringContainsString($form, $page);
        self::assertStringContainsString('Delete account<span class="visually-hidden"> of Alice</span>', $page);
        self::assertStringNotContainsString($form, $this->page($alice), 'readers get no such tool');

        $refused = $this->moderate($admin, $id, 'delete-account');
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('role="alert">Please tick “Delete permanently” to delete the account.', (string) $refused->getBody());
        self::assertNull($this->deletedAt($alice['id']));
        self::assertSame(403, $this->post("/admin/comments/$id/delete-account", ['confirm' => 'yes'], $admin['cookies'])->getStatusCode(), 'without the token');
        self::assertNull($this->deletedAt($alice['id']));

        $response = $this->moderate($admin, $id, 'delete-account', ['confirm' => 'yes']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/talk?done=account-deleted#comments', $response->getHeaderLine('Location'));

        // Exactly what deleting it herself does.
        self::assertNotNull($this->deletedAt($alice['id']));
        self::assertSame(0, $this->countFor('user_identity', $alice['id']));
        self::assertSame(0, $this->countFor('session', $alice['id']));
        self::assertSame(0, $this->countFor('vote', $alice['id']));
        $page = (string) $this->get('/talk?done=account-deleted', $admin['cookies'])->getBody();
        self::assertStringContainsString('role="status">The account was deleted. Its comments stay, under “[deleted user]”.</p>', $page);
        self::assertStringContainsString('Words that stay', $page);
        self::assertStringContainsString('[deleted user]', $page);
        self::assertStringNotContainsString('Alice', $page);
        self::assertStringNotContainsString($form, $page, 'nothing left to delete');
        self::assertStringNotContainsString('The account was deleted', (string) $this->get('/talk?done=account-deleted')->getBody());
    }

    public function testTheQueueDeletesAnAccountToo(): void
    {
        $admin = $this->admin();
        $this->rules($admin, ['comment_default' => 'hold']);
        $bob = $this->reader('Bob');
        $id = $this->posted($bob, 'Buy my stuff');

        $queue = (string) $this->get('/admin/comments', $admin['cookies'])->getBody();
        self::assertStringContainsString('<form method="post" action="/admin/comments/' . $id . '/delete-account" class="inline">', $queue);

        $refused = $this->moderate($admin, $id, 'delete-account', ['back' => 'queue']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('role="alert">Please tick “Delete permanently” to delete the account.', (string) $refused->getBody());
        self::assertStringContainsString('<h1>Comments</h1>', (string) $refused->getBody());
        self::assertNull($this->deletedAt($bob['id']));

        $response = $this->moderate($admin, $id, 'delete-account', ['back' => 'queue', 'confirm' => 'yes']);
        self::assertSame('/admin/comments?done=account-deleted', $response->getHeaderLine('Location'));
        self::assertNotNull($this->deletedAt($bob['id']));
        $queue = (string) $this->get('/admin/comments?done=account-deleted', $admin['cookies'])->getBody();
        self::assertStringContainsString('The account was deleted.', $queue);
        self::assertStringNotContainsString('/admin/comments/' . $id . '/delete-account', $queue);
    }

    public function testTheAdminAccountIsNeverDeletedAndReadersCannotDeleteAccounts(): void
    {
        $admin = $this->admin();
        $own = $this->posted($admin, 'The sovereign speaks');
        $alice = $this->reader('Alice');
        $bob = $this->reader('Bob');
        $bobs = $this->posted($bob, 'Hello');

        self::assertStringNotContainsString('/admin/comments/' . $own . '/delete-account', $this->page($admin));
        $refused = $this->moderate($admin, $own, 'delete-account', ['confirm' => 'yes']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('The admin account can’t be deleted.', (string) $refused->getBody());
        self::assertNull($this->deletedAt($admin['id']));
        self::assertSame(200, $this->get('/admin', $admin['cookies'])->getStatusCode(), 'still signed in');

        self::assertSame(403, $this->post("/admin/comments/$bobs/delete-account", ['confirm' => 'yes', '_csrf' => $alice['csrf']], $alice['cookies'])->getStatusCode());
        self::assertSame(303, $this->post("/admin/comments/$bobs/delete-account", ['confirm' => 'yes'])->getStatusCode(), 'signed out: to the sign-in');
        self::assertNull($this->deletedAt($bob['id']));
    }
}
