ALTER TABLE labs
  ADD COLUMN IF NOT EXISTS folder_id UUID NULL REFERENCES lab_folders(id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS idx_labs_folder_id ON labs(folder_id);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'labs_author_folder_name_uniq'
    ) THEN
        ALTER TABLE labs
          ADD CONSTRAINT labs_author_folder_name_uniq UNIQUE (author_user_id, folder_id, name);
    END IF;
END;
$$;
