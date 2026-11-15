-- View counts (#86): one counter per article and day, never a log of visits. Shown
-- to the admin only.
--
-- A repeat by the same reader on the same day isn't counted again. To tell, each
-- counted view leaves sha256(salt, IP, user agent, article) in view_seen, where the
-- salt is random and lives for one day only. Neither the IP nor the user agent is
-- stored. When the day is over, its salt and its hashes are deleted, and nothing
-- that is left can be traced back to a reader.

CREATE TABLE article_view_day (
    article_id bigint  NOT NULL REFERENCES article (id) ON DELETE CASCADE,
    day        date    NOT NULL,
    views      integer NOT NULL CHECK (views > 0),
    PRIMARY KEY (article_id, day)
);
CREATE INDEX article_view_day_day ON article_view_day (day);

CREATE TABLE view_salt (
    day  date  PRIMARY KEY,
    salt bytea NOT NULL CHECK (octet_length(salt) = 32)
);

CREATE TABLE view_seen (
    hash bytea PRIMARY KEY CHECK (octet_length(hash) = 32),
    day  date  NOT NULL
);
CREATE INDEX view_seen_day ON view_seen (day);
