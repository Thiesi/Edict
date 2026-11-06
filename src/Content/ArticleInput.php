<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Auth\Accounts;

/**
 * What the article form submits, cleaned and checked. The form is re-shown with
 * these values when something is wrong, so nothing typed gets lost.
 */
final readonly class ArticleInput
{
    private const MAX_TITLE = 200;
    private const MAX_TEASER = 1000;
    private const MAX_BODY_BYTES = 2_000_000;
    private const MAX_TAGS = 20;
    private const MAX_TAG_LENGTH = 64;

    public function __construct(
        public string $title,
        public string $slug,
        public string $lang,
        public string $teaser,
        public string $body,
        public string $tags,
    ) {}

    /**
     * @param array<mixed>|object|null $form
     */
    public static function fromForm(array|object|null $form): self
    {
        return new self(
            trim(self::field($form, 'title')),
            trim(self::field($form, 'slug')),
            self::field($form, 'lang'),
            trim(self::field($form, 'teaser')),
            self::field($form, 'body'),
            self::field($form, 'tags'),
        );
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
     * Invalid UTF-8 can't be stored, so it counts as empty and the form says so.
     *
     * @param array<mixed>|object|null $form
     */
    private static function field(array|object|null $form, string $name): string
    {
        $value = is_array($form) ? ($form[$name] ?? null) : null;

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? str_replace("\0", '', $value) : '';
    }
}
