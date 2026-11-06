-- Media store, uploads and the job queue (S5).

CREATE TABLE media (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sha256        text NOT NULL UNIQUE,      -- of the original upload, so the same file is stored once
    kind          text NOT NULL CHECK (kind IN ('image', 'file')),
    extension     text NOT NULL,             -- from the file's content, never from its name
    bytes         bigint NOT NULL,
    original_name text NOT NULL,             -- for display only; it never becomes a path
    width         integer,
    height        integer,
    status        text NOT NULL CHECK (status IN ('processing', 'ready', 'failed')),
    error         text,
    created_at    timestamptz NOT NULL
);

-- Deterministic from (media, preset), so orphans can be found with a query.
CREATE TABLE media_variant (
    media_id bigint NOT NULL REFERENCES media (id) ON DELETE CASCADE,
    preset   text NOT NULL,                  -- for example "w960-webp" or "full"
    format   text NOT NULL,
    width    integer NOT NULL,
    height   integer NOT NULL,
    bytes    bigint NOT NULL,
    PRIMARY KEY (media_id, preset)
);

CREATE TABLE upload (
    id             text PRIMARY KEY,         -- random, and also the temp file's name
    original_name  text NOT NULL,
    total_bytes    bigint NOT NULL CHECK (total_bytes > 0),
    received_bytes bigint NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL
);

CREATE TABLE job (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type       text NOT NULL,
    payload    jsonb NOT NULL,
    state      text NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'running', 'done', 'failed')),
    attempts   integer NOT NULL DEFAULT 0,
    run_after  timestamptz NOT NULL,
    locked_at  timestamptz,
    error      text,
    created_at timestamptz NOT NULL
);
CREATE INDEX job_ready_idx ON job (run_after, id) WHERE state = 'queued';
