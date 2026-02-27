DO $$
BEGIN
  DELETE FROM lab_shared_users target
  USING (
    SELECT ctid
    FROM (
      SELECT ctid,
             ROW_NUMBER() OVER (
               PARTITION BY lab_id, user_id
               ORDER BY created_at ASC, ctid ASC
             ) AS rn
      FROM lab_shared_users
    ) ranked
    WHERE ranked.rn > 1
  ) duplicates
  WHERE target.ctid = duplicates.ctid;

  IF NOT EXISTS (
    SELECT 1
    FROM pg_constraint c
    JOIN pg_class t ON t.oid = c.conrelid
    JOIN pg_namespace n ON n.oid = t.relnamespace
    WHERE n.nspname = 'public'
      AND t.relname = 'lab_shared_users'
      AND c.contype = 'p'
  ) THEN
    ALTER TABLE lab_shared_users
      ADD CONSTRAINT lab_shared_users_pkey PRIMARY KEY (lab_id, user_id);
  END IF;
END;
$$;

CREATE INDEX IF NOT EXISTS idx_lab_shared_users_user_id
  ON lab_shared_users (user_id);
