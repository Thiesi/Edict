-- Reading time (#88): "6 min read" from the published body's words, at 220 words a
-- minute, rounded up, at least one minute.
--
-- It follows body_text (0014), the published body as plain text, which only publishing
-- and a restore write. As a stored generated column it is computed exactly then, kept
-- on the row, and can't drift from the text: every way an article goes live (publish,
-- republish, scheduled publish, restore) and the rows already here (on ADD COLUMN) get
-- it from the same function. Autosaves don't touch body_text, so they don't change it.

CREATE FUNCTION edict_reading_minutes(body_text text) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE
RETURN greatest(1, ceil(regexp_count(body_text, '\S+') / 220.0))::integer;

ALTER TABLE article ADD COLUMN reading_minutes integer GENERATED ALWAYS AS (edict_reading_minutes(body_text)) STORED;
