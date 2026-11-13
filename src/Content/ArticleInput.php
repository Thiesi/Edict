<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Auth\Accounts;

/**
 * An article's editable fields: the draft that autosave stores and publishing
 * checks. A draft may be incomplete, so errors() only matters when publishing.
 */
final readonly class ArticleInput
{
    /** Autosave's hard limit on one draft, far above any real article. */
    public const MAX_DRAFT_BYTES = 4_000_000;

    private const MAX_TITLE = 200;
    private const MAX_TEASER = 1000;
    private const MAX_BODY_BYTES = 2_000_000;
    private const MAX_TAGS = 20;
    private const MAX_TAG_LENGTH = 64;
    private const MAX_COVER_ALT = 500;

    public function __construct(
        public string $title,
        public string $slug,
        public string $lang,
        public string $teaser,
        public string $body,
        public string $tags,
        public ?int $cover = null,
        public string $coverAlt = '',
    ) {}

    /**
     * @param array<mixed> $data as the editor sends it, or as a stored draft holds it
     */
    public static function fromArray(array $data): self
    {
        return new self(
            trim(self::field($data, 'title')),
            trim(self::field($data, 'slug')),
            self::field($data, 'lang'),
            trim(self::field($data, 'teaser')),
            Body::encode(Body::clean($data['body'] ?? null)),
            self::field($data, 'tags'),
            self::mediaId($data['cover'] ?? null),
            trim(self::field($data, 'coverAlt')),
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        return self::fromArray(is_array($data) ? $data : []);
    }

    public function toJson(): string
    {
        return json_encode([
            'title' => $this->title,
            'slug' => $this->slug,
            'lang' => $this->lang,
            'teaser' => $this->teaser,
            'body' => json_decode($this->body),
            'tags' => $this->tags,
            'cover' => $this->cover,
            'coverAlt' => $this->coverAlt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Whether readers would see something different. The address doesn't count: a draft
     * leaves it empty to keep the published one.
     */
    public function sameContentAs(self $other): bool
    {
        return $this->title === $other->title
            && $this->lang === $other->lang
            && $this->teaser === $other->teaser
            && self::sorted(Body::decode($this->body)) === self::sorted(Body::decode($other->body))
            && $this->cover === $other->cover
            && $this->coverAlt === $other->coverAlt
            && self::tagNames($this) === self::tagNames($other);
    }

    /**
     * The database hands JSON back with its keys reordered; the content is the same.
     */
    private static function sorted(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }

    /**
     * @return list<string> in a fixed order: the order they were typed in doesn't matter
     */
    private static function tagNames(self $input): array
    {
        $names = array_column($input->tagList(), 'name');
        sort($names);

        return $names;
    }

    /**
     * @return array<string, string> messages by field
     */
    public function errors(): array
    {
        $errors = [];
        if ($this->title === '' || mb_strlen($this->title) > self::MAX_TITLE) {
            $errors['title'] = 'Please enter a title of up to ' . self::MAX_TITLE . ' characters.';
        }
        if ($this->slug !== '' && !Slugs::isValid($this->slug)) {
            $errors['slug'] = 'Use lowercase letters, digits and single hyphens, up to ' . Slugs::MAX_LENGTH . ' characters.';
        } elseif (Slugs::isReserved($this->slug)) {
            $errors['slug'] = 'That address is reserved by the site.';
        }
        if (!in_array($this->lang, ['de', 'en'], true)) {
            $errors['lang'] = 'Please choose German or English.';
        }
        if (mb_strlen($this->teaser) > self::MAX_TEASER) {
            $errors['teaser'] = 'The teaser can have up to ' . self::MAX_TEASER . ' characters.';
        }
        if (mb_strlen($this->coverAlt) > self::MAX_COVER_ALT) {
            $errors['coverAlt'] = 'The cover description can have up to ' . self::MAX_COVER_ALT . ' characters.';
        }
        if (strlen($this->body) > self::MAX_BODY_BYTES) {
            $errors['body'] = 'The text is too long.';
        }
        $tags = $this->tagList();
        if (count($tags) > self::MAX_TAGS) {
            $errors['tags'] = 'An article can have up to ' . self::MAX_TAGS . ' tags.';
        }
        foreach ($tags as $tag) {
            if (mb_strlen($tag['name']) > self::MAX_TAG_LENGTH) {
                $errors['tags'] = 'A tag can have up to ' . self::MAX_TAG_LENGTH . ' characters.';
            }
        }

        return $errors;
    }

    /**
     * Comma-separated names, one tag per slug. The first spelling wins.
     *
     * @return list<array{slug: string, name: string}>
     */
    public function tagList(): array
    {
        $tags = [];
        foreach (explode(',', $this->tags) as $name) {
            $clean = Accounts::cleanName($name);
            $slug = $clean === null ? '' : Slugs::fromText($clean);
            if ($clean !== null && $slug !== '' && !isset($tags[$slug])) {
                $tags[$slug] = ['slug' => $slug, 'name' => $clean];
            }
        }

        return array_values($tags);
    }

    /**
     * The editor keeps it in a form field, so it arrives as text.
     */
    private static function mediaId(mixed $value): ?int
    {
        if (is_string($value) && preg_match('~^[1-9][0-9]{0,17}$~D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function field(array $data, string $name): string
    {
        $value = $data[$name] ?? null;

        return is_string($value) ? str_replace("\0", '', $value) : '';
    }
}
