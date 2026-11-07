-- Audio and video (S6). "meta" holds what the player needs: duration and waveform peaks.

ALTER TABLE media DROP CONSTRAINT media_kind_check;
ALTER TABLE media ADD CONSTRAINT media_kind_check CHECK (kind IN ('image', 'audio', 'video', 'file'));
ALTER TABLE media ADD COLUMN meta jsonb NOT NULL DEFAULT '{}';
