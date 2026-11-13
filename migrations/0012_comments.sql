-- Comments (S10). Threads keep a materialised path of zero-padded IDs, so one
-- ordered query returns a whole thread and a subtree is a prefix.

CREATE TABLE comment (
    id         bigserial PRIMARY KEY,
    article_id bigint NOT NULL REFERENCES article (id) ON DELETE CASCADE,
    user_id    bigint REFERENCES app_user (id) ON DELETE SET NULL,
    parent_id  bigint REFERENCES comment (id) ON DELETE CASCADE,
    path       text NOT NULL DEFAULT '',
    depth      integer NOT NULL DEFAULT 0,
    body       text NOT NULL CHECK (char_length(body) BETWEEN 1 AND 5000),
    -- visible: shown. held: waits for the sovereign. removed: gone, shown as "[removed]"
    -- only where replies hang off it.
    state      text NOT NULL CHECK (state IN ('visible', 'held', 'removed')),
    pinned     boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL,
    edited_at  timestamptz
);
CREATE INDEX comment_thread ON comment (article_id, path);
CREATE INDEX comment_author ON comment (user_id, created_at);
CREATE INDEX comment_held ON comment (created_at) WHERE state = 'held';

-- 'site' follows the site's default and its auto-close; the others override both.
ALTER TABLE article
    ADD COLUMN comments_mode text NOT NULL DEFAULT 'site' CHECK (comments_mode IN ('site', 'open', 'hold', 'closed'));

-- Approved authors: their comments skip the queue, whatever the mode.
ALTER TABLE app_user ADD COLUMN trusted boolean NOT NULL DEFAULT false;
