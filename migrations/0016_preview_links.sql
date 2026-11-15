-- Private preview links (#85): one per piece, showing its current draft to anyone
-- holding the link, for seven days. Only the SHA-256 of the token is stored, so the
-- table and its backups contain no usable link. Publishing, scheduling and trashing
-- delete the row; deleting the piece takes it along.

CREATE TABLE preview_link (
    article_id bigint PRIMARY KEY REFERENCES article (id) ON DELETE CASCADE,
    token_hash text NOT NULL UNIQUE,         -- hex SHA-256 of the token in the link
    created_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL
);
