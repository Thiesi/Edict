-- A finished upload remembers its media until the sweep removes it, so asking again
-- after a lost answer gets the same result.
ALTER TABLE upload ADD COLUMN media_id bigint REFERENCES media (id) ON DELETE SET NULL;
