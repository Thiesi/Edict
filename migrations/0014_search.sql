-- Full-text search (S10b): each article indexed in its own language, German or
-- English stemming, inside PostgreSQL. body_text is the published body's words,
-- refreshed on every publish.

CREATE FUNCTION edict_body_text(body jsonb) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE
RETURN coalesce((
    SELECT string_agg(part #>> '{}', ' ')
    FROM (
        SELECT jsonb_path_query(body, 'strict $.** ? (@.type == "text").text') AS part
        UNION ALL
        SELECT jsonb_path_query(body, 'strict $.** ? (@.type == "image" || @.type == "player" || @.type == "file").attrs.caption')
        UNION ALL
        SELECT jsonb_path_query(body, 'strict $.** ? (@.type == "image").attrs.alt')
        UNION ALL
        SELECT jsonb_path_query(body, 'strict $.** ? (@.type == "gallery").attrs.items[*].alt')
    ) parts
), '');

ALTER TABLE article ADD COLUMN body_text text NOT NULL DEFAULT '';
UPDATE article SET body_text = edict_body_text(body_json);

ALTER TABLE article ADD COLUMN search tsvector GENERATED ALWAYS AS (
    setweight(to_tsvector(CASE WHEN lang = 'de' THEN 'german'::regconfig ELSE 'english'::regconfig END, title), 'A')
    || setweight(to_tsvector(CASE WHEN lang = 'de' THEN 'german'::regconfig ELSE 'english'::regconfig END, teaser), 'B')
    || setweight(to_tsvector(CASE WHEN lang = 'de' THEN 'german'::regconfig ELSE 'english'::regconfig END, body_text), 'C')
) STORED;
CREATE INDEX article_search ON article USING gin (search);
