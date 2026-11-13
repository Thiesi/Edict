-- Published versions (S10b): every publish keeps what went live, the last 20 per
-- article, to restore into the draft. And when an article was last changed after its
-- first publish, for "Updated on".

CREATE TABLE article_revision (
    id           bigserial PRIMARY KEY,
    article_id   bigint NOT NULL REFERENCES article (id) ON DELETE CASCADE,
    content      jsonb NOT NULL,
    published_at timestamptz NOT NULL
);
CREATE INDEX article_revision_newest ON article_revision (article_id, id DESC);

ALTER TABLE article ADD COLUMN revised_at timestamptz;
