-- Cover images: the picture above an article and beside it in listings, later also
-- the one link previews show. Deleting the media only removes it from the article.

ALTER TABLE article
    ADD COLUMN cover_media_id bigint REFERENCES media (id) ON DELETE SET NULL,
    ADD COLUMN cover_alt text NOT NULL DEFAULT '' CHECK (char_length(cover_alt) <= 500);
