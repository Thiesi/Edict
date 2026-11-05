-- Users, admin credentials, sessions and login throttling (S2a).

CREATE TABLE app_user (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    display_name text NOT NULL CHECK (char_length(display_name) BETWEEN 1 AND 64),
    is_admin     boolean NOT NULL DEFAULT false,
    banned_until timestamptz,
    deleted_at   timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);

-- There is exactly one sovereign: at most one row may have is_admin = true.
CREATE UNIQUE INDEX app_user_single_admin ON app_user (is_admin) WHERE is_admin;

CREATE TABLE admin_credential (
    user_id        bigint PRIMARY KEY REFERENCES app_user (id) ON DELETE CASCADE,
    username       text NOT NULL UNIQUE,
    password_hash  text NOT NULL,
    totp_secret    text NOT NULL,            -- encrypted, see Edict\Auth\SecretBox
    totp_last_step bigint NOT NULL DEFAULT 0, -- last accepted time step; blocks code replay
    updated_at     timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE session (
    token_hash   text PRIMARY KEY,           -- SHA-256 of the cookie value; the token itself is never stored
    user_id      bigint NOT NULL REFERENCES app_user (id) ON DELETE CASCADE,
    csrf_token   text NOT NULL,
    created_at   timestamptz NOT NULL,
    last_seen_at timestamptz NOT NULL,
    expires_at   timestamptz NOT NULL
);
CREATE INDEX session_user_idx ON session (user_id);
CREATE INDEX session_expiry_idx ON session (expires_at);

CREATE TABLE login_attempt (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bucket       text NOT NULL,              -- keyed HMAC of IP or username, never the raw value
    attempted_at timestamptz NOT NULL
);
CREATE INDEX login_attempt_lookup_idx ON login_attempt (bucket, attempted_at);
