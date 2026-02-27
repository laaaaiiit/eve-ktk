DROP INDEX IF EXISTS idx_lab_folders_owner_parent_name_uniq_root;
CREATE UNIQUE INDEX idx_lab_folders_owner_parent_name_uniq_root
  ON lab_folders (owner_user_id, LOWER(name))
  WHERE parent_id IS NULL;

DROP INDEX IF EXISTS idx_lab_folders_owner_parent_name_uniq_nested;
CREATE UNIQUE INDEX idx_lab_folders_owner_parent_name_uniq_nested
  ON lab_folders (owner_user_id, parent_id, LOWER(name))
  WHERE parent_id IS NOT NULL;

DROP INDEX IF EXISTS idx_labs_author_folder_name_uniq_root;
CREATE UNIQUE INDEX idx_labs_author_folder_name_uniq_root
  ON labs (author_user_id, LOWER(name))
  WHERE folder_id IS NULL;

DROP INDEX IF EXISTS idx_labs_author_folder_name_uniq_nested;
CREATE UNIQUE INDEX idx_labs_author_folder_name_uniq_nested
  ON labs (author_user_id, folder_id, LOWER(name))
  WHERE folder_id IS NOT NULL;
