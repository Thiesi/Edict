-- Site settings (S9): the theme, title, description, menu and footer. One row per
-- setting, so a new one needs no migration.

CREATE TABLE setting (
    key   text PRIMARY KEY,
    value jsonb NOT NULL
);
