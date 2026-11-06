-- Editor drafts and static pages (S4).

ALTER TABLE article
    ADD COLUMN kind text NOT NULL DEFAULT 'article' CHECK (kind IN ('article', 'page')),
    -- Autosave writes here, never to the live columns, so half-finished edits of a
    -- published article stay private. NULL means nothing differs from the live version.
    ADD COLUMN draft jsonb,
    -- Raised by every save. A save based on an older version is refused, so a second
    -- tab can't silently overwrite newer text.
    ADD COLUMN draft_version bigint NOT NULL DEFAULT 0,
    -- The open editor that saved last. If it repeats a save whose answer got lost, its
    -- version looks old, but nothing of anyone else's can be overwritten.
    ADD COLUMN draft_writer text;
