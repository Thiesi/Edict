-- External login identities (S2b). Keyed on the provider's stable user ID, never
-- on a changeable name or an email address. Neither is stored here.

CREATE TABLE user_identity (
    provider   text NOT NULL CHECK (provider IN ('google', 'twitch')),
    subject    text NOT NULL,                -- the provider's stable user ID ("sub")
    user_id    bigint NOT NULL REFERENCES app_user (id) ON DELETE CASCADE,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (provider, subject),
    UNIQUE (user_id, provider)               -- at most one Google and one Twitch login per account
);
