<?php

declare(strict_types=1);

namespace Edict\Site;

/**
 * Site settings in the `setting` table, read once per request. The admin can look at
 * another theme without applying it; only they see it.
 */
final class Settings
{
    public const MAX_LINKS = 12;
    private const MAX_LABEL = 60;
    private const MAX_URL = 500;
    private const MAX_TITLE = 100;
    private const MAX_DESCRIPTION = 300;

    private ?SiteSettings $loaded = null;
    private ?string $preview = null;

    public function __construct(private readonly \PDO $pdo) {}

    public function site(): SiteSettings
    {
        if ($this->loaded === null) {
            $values = [];
            $stmt = $this->pdo->query('SELECT key, value::text AS value FROM setting');
            foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
                if (is_array($row) && is_string($row['key'] ?? null) && is_string($row['value'] ?? null)) {
                    $values[$row['key']] = json_decode($row['value'], true);
                }
            }
            $theme = is_string($values['theme'] ?? null) && Themes::exists($values['theme']) ? $values['theme'] : Themes::DEFAULT;
            $this->loaded = new SiteSettings(
                $theme,
                self::text($values['title'] ?? null, 'Edict'),
                self::text($values['description'] ?? null, ''),
                self::links($values['menu'] ?? null),
                self::links($values['footer'] ?? null),
                self::text($values['imprint'] ?? null, ''),
                self::text($values['privacy'] ?? null, ''),
            );
        }

        return $this->preview === null ? $this->loaded : $this->loaded->withTheme($this->preview);
    }

    /**
     * This request only, and only for the admin: see SitePreviewMiddleware.
     */
    public function preview(?string $theme): void
    {
        $this->preview = $theme !== null && Themes::exists($theme) ? $theme : null;
    }

    public function applyTheme(string $theme): bool
    {
        if (!Themes::exists($theme)) {
            return false;
        }
        $this->store(['theme' => $theme]);

        return true;
    }

    /**
     * Checks and stores the form on the site settings page.
     *
     * @param array<mixed> $form
     *
     * @return array<string, string> what is wrong, by field; nothing is stored then
     */
    public function save(array $form): array
    {
        $errors = [];
        $title = trim(self::field($form, 'title'));
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Please enter a site name of up to ' . self::MAX_TITLE . ' characters.';
        }
        $description = trim(self::field($form, 'description'));
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $errors['description'] = 'The description can have up to ' . self::MAX_DESCRIPTION . ' characters.';
        }
        $values = ['title' => $title, 'description' => $description];
        foreach (['menu', 'footer'] as $name) {
            $parsed = self::parseLinks(self::field($form, $name));
            if (is_string($parsed)) {
                $errors[$name] = $parsed;
            } else {
                $values[$name] = $parsed;
            }
        }
        foreach (['imprint', 'privacy'] as $name) {
            $slug = self::field($form, $name);
            if ($slug !== '' && preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~D', $slug) !== 1) {
                $errors[$name] = 'Please choose one of the pages.';
            }
            $values[$name] = $slug;
        }

        if ($errors === []) {
            $this->store($values);
        }

        return $errors;
    }

    /**
     * Links as the form shows them: one per line, "Label | address".
     *
     * @param list<array{label: string, url: string}> $links
     */
    public static function linesOf(array $links): string
    {
        return implode("\n", array_map(static fn(array $link): string => $link['label'] . ' | ' . $link['url'], $links));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function store(array $values): void
    {
        $upsert = $this->pdo->prepare('INSERT INTO setting (key, value) VALUES (?, ?::jsonb) ON CONFLICT (key) DO UPDATE SET value = excluded.value');
        foreach ($values as $key => $value) {
            $upsert->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
        $this->loaded = null;
    }

    /**
     * @return list<array{label: string, url: string}>|string the links, or what is wrong
     */
    private static function parseLinks(string $text): array|string
    {
        $links = [];
        foreach (preg_split('~\R~u', $text) ?: [] as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode('|', $line, 2);
            $label = trim($parts[0]);
            $url = trim($parts[1] ?? '');
            if ($label === '' || $url === '' || mb_strlen($label) > self::MAX_LABEL || strlen($url) > self::MAX_URL) {
                return 'Line ' . ($number + 1) . ': write "Label | address", with a label of up to ' . self::MAX_LABEL . ' characters.';
            }
            if (!self::isSafeUrl($url)) {
                return 'Line ' . ($number + 1) . ': an address starts with / or https://, or is a mailto: link.';
            }
            $links[] = ['label' => $label, 'url' => $url];
        }
        if (count($links) > self::MAX_LINKS) {
            return 'Up to ' . self::MAX_LINKS . ' links.';
        }

        return $links;
    }

    private static function isSafeUrl(string $url): bool
    {
        return preg_match('~^(/(?!/)\S*|https?://[^\s/]+\S*|mailto:\S+)$~iD', $url) === 1;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private static function links(mixed $value): array
    {
        $links = [];
        foreach (is_array($value) ? $value : [] as $link) {
            if (is_array($link) && is_string($link['label'] ?? null) && is_string($link['url'] ?? null) && self::isSafeUrl($link['url'])) {
                $links[] = ['label' => $link['label'], 'url' => $link['url']];
            }
        }

        return $links;
    }

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<mixed> $form
     */
    private static function field(array $form, string $name): string
    {
        $value = $form[$name] ?? null;

        return is_string($value) ? str_replace("\0", '', $value) : '';
    }
}
