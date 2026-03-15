ALTER TABLE auth_sessions
  ADD COLUMN IF NOT EXISTS ended_at TIMESTAMPTZ NULL,
  ADD COLUMN IF NOT EXISTS ended_reason VARCHAR(32) NULL;

CREATE INDEX IF NOT EXISTS idx_auth_sessions_ended_at ON auth_sessions(ended_at);

UPDATE auth_sessions
SET ended_at = NOW(),
    ended_reason = COALESCE(ended_reason, 'expired')
WHERE ended_at IS NULL
  AND expires_at < NOW();
