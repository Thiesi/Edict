-- Votes (S8): one like per article and account. The public count is the real likes
-- plus the sovereign's adjustment, which can be anything.

CREATE TABLE vote (
    article_id bigint NOT NULL REFERENCES article (id) ON DELETE CASCADE,
    user_id    bigint NOT NULL REFERENCES app_user (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL,
    PRIMARY KEY (article_id, user_id)
);
CREATE INDEX vote_user ON vote (user_id);

ALTER TABLE article
    ADD COLUMN vote_adjustment integer NOT NULL DEFAULT 0 CHECK (vote_adjustment BETWEEN -1000000000 AND 1000000000);
