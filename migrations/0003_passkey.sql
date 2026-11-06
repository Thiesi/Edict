-- Passkeys for the admin account (S2c).

CREATE TABLE passkey (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id       bigint NOT NULL REFERENCES app_user (id) ON DELETE CASCADE,
    credential_id text NOT NULL UNIQUE,      -- base64url
    public_key    text NOT NULL,             -- PEM
    sign_count    bigint NOT NULL DEFAULT 0,
    name          text NOT NULL CHECK (char_length(name) BETWEEN 1 AND 64),
    created_at    timestamptz NOT NULL,
    last_used_at  timestamptz
);
CREATE INDEX passkey_user_idx ON passkey (user_id);

-- Challenges live in an encrypted cookie, so anonymous requests write nothing. Only
-- a challenge that came with a valid signature lands here, which makes it single-use.
CREATE TABLE webauthn_used_challenge (
    challenge_hash text PRIMARY KEY,
    expires_at     timestamptz NOT NULL
);
