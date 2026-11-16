-- A reader who deletes their own comment erases its text (#101). Where replies hang
-- off it, the row stays as an empty "[removed]" placeholder so the thread holds
-- together; otherwise the row goes. The admin's "Remove" keeps the text, as evidence.
-- Only a removed comment may be empty.

ALTER TABLE comment DROP CONSTRAINT comment_body_check;
ALTER TABLE comment ADD CONSTRAINT comment_body_check
    CHECK (char_length(body) BETWEEN 1 AND 5000 OR (body = '' AND state = 'removed'));
