CREATE EXTENSION IF NOT EXISTS pgcrypto;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'users'
          AND column_name = 'id'
          AND data_type = 'uuid'
    ) THEN
        RAISE NOTICE 'UUID migration already applied';
        RETURN;
    END IF;

    ALTER TABLE roles ADD COLUMN IF NOT EXISTS id_new UUID DEFAULT gen_random_uuid();
    ALTER TABLE users ADD COLUMN IF NOT EXISTS id_new UUID DEFAULT gen_random_uuid();
    ALTER TABLE clouds ADD COLUMN IF NOT EXISTS id_new UUID DEFAULT gen_random_uuid();
    ALTER TABLE cloud_users ADD COLUMN IF NOT EXISTS id_new UUID DEFAULT gen_random_uuid();

    ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id_new UUID;
    ALTER TABLE cloud_users ADD COLUMN IF NOT EXISTS cloud_id_new UUID;
    ALTER TABLE cloud_users ADD COLUMN IF NOT EXISTS user_id_new UUID;
    ALTER TABLE auth_sessions ADD COLUMN IF NOT EXISTS user_id_new UUID;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'labs' AND column_name = 'author_user_id' AND udt_name = 'int8'
    ) THEN
        ALTER TABLE labs ADD COLUMN IF NOT EXISTS author_user_id_new UUID;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'lab_shared_users' AND column_name = 'user_id' AND udt_name = 'int8'
    ) THEN
        ALTER TABLE lab_shared_users ADD COLUMN IF NOT EXISTS user_id_new UUID;
    END IF;

    UPDATE users u
    SET role_id_new = r.id_new
    FROM roles r
    WHERE u.role_id = r.id;

    UPDATE cloud_users cu
    SET cloud_id_new = c.id_new
    FROM clouds c
    WHERE cu.cloud_id = c.id;

    UPDATE cloud_users cu
    SET user_id_new = u.id_new
    FROM users u
    WHERE cu.user_id = u.id;

    UPDATE auth_sessions s
    SET user_id_new = u.id_new
    FROM users u
    WHERE s.user_id = u.id;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'labs' AND column_name = 'author_user_id_new'
    ) THEN
        UPDATE labs l
        SET author_user_id_new = u.id_new
        FROM users u
        WHERE l.author_user_id = u.id;
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'lab_shared_users' AND column_name = 'user_id_new'
    ) THEN
        UPDATE lab_shared_users s
        SET user_id_new = u.id_new
        FROM users u
        WHERE s.user_id = u.id;
    END IF;

    ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_id_fkey;
    ALTER TABLE cloud_users DROP CONSTRAINT IF EXISTS cloud_users_cloud_id_fkey;
    ALTER TABLE cloud_users DROP CONSTRAINT IF EXISTS cloud_users_user_id_fkey;
    ALTER TABLE auth_sessions DROP CONSTRAINT IF EXISTS auth_sessions_user_id_fkey;
    ALTER TABLE labs DROP CONSTRAINT IF EXISTS labs_author_user_id_fkey;
    ALTER TABLE lab_shared_users DROP CONSTRAINT IF EXISTS lab_shared_users_user_id_fkey;

    ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_pkey;
    ALTER TABLE roles DROP COLUMN id;
    ALTER TABLE roles RENAME COLUMN id_new TO id;
    ALTER TABLE roles ALTER COLUMN id SET NOT NULL;
    ALTER TABLE roles ADD PRIMARY KEY (id);

    ALTER TABLE users DROP CONSTRAINT IF EXISTS users_pkey;
    ALTER TABLE users DROP COLUMN id;
    ALTER TABLE users RENAME COLUMN id_new TO id;
    ALTER TABLE users ALTER COLUMN id SET NOT NULL;
    ALTER TABLE users DROP COLUMN role_id;
    ALTER TABLE users RENAME COLUMN role_id_new TO role_id;
    ALTER TABLE users ALTER COLUMN role_id SET NOT NULL;
    ALTER TABLE users ADD PRIMARY KEY (id);
    ALTER TABLE users ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id);

    ALTER TABLE clouds DROP CONSTRAINT IF EXISTS clouds_pkey;
    ALTER TABLE clouds DROP COLUMN id;
    ALTER TABLE clouds RENAME COLUMN id_new TO id;
    ALTER TABLE clouds ALTER COLUMN id SET NOT NULL;
    ALTER TABLE clouds ADD PRIMARY KEY (id);

    ALTER TABLE cloud_users DROP CONSTRAINT IF EXISTS cloud_users_pkey;
    ALTER TABLE cloud_users DROP COLUMN id;
    ALTER TABLE cloud_users RENAME COLUMN id_new TO id;
    ALTER TABLE cloud_users ALTER COLUMN id SET NOT NULL;
    ALTER TABLE cloud_users DROP COLUMN cloud_id;
    ALTER TABLE cloud_users DROP COLUMN user_id;
    ALTER TABLE cloud_users RENAME COLUMN cloud_id_new TO cloud_id;
    ALTER TABLE cloud_users RENAME COLUMN user_id_new TO user_id;
    ALTER TABLE cloud_users ALTER COLUMN cloud_id SET NOT NULL;
    ALTER TABLE cloud_users ALTER COLUMN user_id SET NOT NULL;
    ALTER TABLE cloud_users ADD PRIMARY KEY (id);
    ALTER TABLE cloud_users ADD CONSTRAINT cloud_users_cloud_id_fkey FOREIGN KEY (cloud_id) REFERENCES clouds(id) ON DELETE CASCADE;
    ALTER TABLE cloud_users ADD CONSTRAINT cloud_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

    ALTER TABLE auth_sessions DROP COLUMN user_id;
    ALTER TABLE auth_sessions RENAME COLUMN user_id_new TO user_id;
    ALTER TABLE auth_sessions ALTER COLUMN user_id SET NOT NULL;
    ALTER TABLE auth_sessions ADD CONSTRAINT auth_sessions_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'labs' AND column_name = 'author_user_id_new'
    ) THEN
        ALTER TABLE labs DROP COLUMN author_user_id;
        ALTER TABLE labs RENAME COLUMN author_user_id_new TO author_user_id;
        ALTER TABLE labs ALTER COLUMN author_user_id SET NOT NULL;
        ALTER TABLE labs ADD CONSTRAINT labs_author_user_id_fkey FOREIGN KEY (author_user_id) REFERENCES users(id);
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'lab_shared_users' AND column_name = 'user_id_new'
    ) THEN
        ALTER TABLE lab_shared_users DROP COLUMN user_id;
        ALTER TABLE lab_shared_users RENAME COLUMN user_id_new TO user_id;
        ALTER TABLE lab_shared_users ALTER COLUMN user_id SET NOT NULL;
        ALTER TABLE lab_shared_users ADD CONSTRAINT lab_shared_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;

    DROP INDEX IF EXISTS idx_users_role_id;
    CREATE INDEX IF NOT EXISTS idx_users_role_id ON users(role_id);

    DROP INDEX IF EXISTS idx_auth_sessions_user_id;
    CREATE INDEX IF NOT EXISTS idx_auth_sessions_user_id ON auth_sessions(user_id);

    DROP INDEX IF EXISTS idx_cloud_users_user_id;
    DROP INDEX IF EXISTS idx_cloud_users_cloud_id;
    CREATE INDEX IF NOT EXISTS idx_cloud_users_user_id ON cloud_users(user_id);
    CREATE INDEX IF NOT EXISTS idx_cloud_users_cloud_id ON cloud_users(cloud_id);

    DROP INDEX IF EXISTS idx_labs_author_user_id;
    CREATE INDEX IF NOT EXISTS idx_labs_author_user_id ON labs(author_user_id);

    DROP INDEX IF EXISTS idx_lab_shared_users_user_id;
    CREATE INDEX IF NOT EXISTS idx_lab_shared_users_user_id ON lab_shared_users(user_id);
END;
$$;
