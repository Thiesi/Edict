-- The admin's notification address: comment alerts and security notices go here,
-- with ALERT_EMAIL as the fallback. Never used to sign in or to reset anything.

ALTER TABLE admin_credential
    ADD COLUMN email text CHECK (email IS NULL OR char_length(email) BETWEEN 3 AND 254);
