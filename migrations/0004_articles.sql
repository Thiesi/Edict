-- Articles, renamed-slug redirects and tags (S3).

CREATE TABLE article (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug         text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND char_length(slug) <= 120),
    lang         text NOT NULL CHECK (lang IN ('de', 'en')),
    title        text NOT NULL CHECK (char_length(title) BETWEEN 1 AND 200),
    teaser       text NOT NULL DEFAULT '' CHECK (char_length(teaser) <= 1000),
    body_json    jsonb NOT NULL,             -- ProseMirror document, the source of truth; HTML comes from Edict\Content\Body
    status       text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'unpublished')),
    published_at timestamptz,                -- set on first publish and kept, so republishing doesn't move an article
    created_at   timestamptz NOT NULL,
    updated_at   timestamptz NOT NULL,
    deleted_at   timestamptz                 -- in the trash since then
);

-- The public listing: newest first.
CREATE INDEX article_public_idx ON article (published_at DESC, id DESC)
    WHERE status = 'published' AND deleted_at IS NULL;

-- Old slugs point at the article, not at each other, so renaming twice leaves no chains.
CREATE TABLE slug_redirect (
    old_slug   text PRIMARY KEY,
    article_id bigint NOT NULL REFERENCES article (id) ON DELETE CASCADE
);
CREATE INDEX slug_redirect_article_idx ON slug_redirect (article_id);

CREATE TABLE tag (
    id   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug text NOT NULL UNIQUE,
    name text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 64)
);

CREATE TABLE article_tag (
    article_id bigint NOT NULL REFERENCES article (id) ON DELETE CASCADE,
    tag_id     bigint NOT NULL REFERENCES tag (id) ON DELETE CASCADE,
    PRIMARY KEY (article_id, tag_id)
);
CREATE INDEX article_tag_tag_idx ON article_tag (tag_id);
