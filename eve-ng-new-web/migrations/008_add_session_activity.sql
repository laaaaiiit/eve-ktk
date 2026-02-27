ALTER TABLE auth_sessions
  ADD COLUMN IF NOT EXISTS last_activity TIMESTAMPTZ NOT NULL DEFAULT NOW();

UPDATE auth_sessions
SET last_activity = COALESCE(last_activity, created_at, NOW())
WHERE last_activity IS NULL;

CREATE INDEX IF NOT EXISTS idx_auth_sessions_last_activity ON auth_sessions(last_activity);
