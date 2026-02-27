ALTER TABLE labs
  ADD COLUMN IF NOT EXISTS source_lab_id UUID NULL REFERENCES labs(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_labs_source_lab_id
  ON labs(source_lab_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_labs_author_source_unique
  ON labs(author_user_id, source_lab_id)
  WHERE source_lab_id IS NOT NULL;
