-- Remove legacy case-sensitive unique constraints that duplicate
-- newer case-insensitive partial unique indexes.
ALTER TABLE public.lab_folders
	DROP CONSTRAINT IF EXISTS lab_folders_owner_parent_name_uniq;

ALTER TABLE public.labs
	DROP CONSTRAINT IF EXISTS labs_author_folder_name_uniq;
