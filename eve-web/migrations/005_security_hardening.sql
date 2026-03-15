CREATE TABLE IF NOT EXISTS auth_login_throttle (
    throttle_key character(64) PRIMARY KEY,
    username character varying(190) NOT NULL DEFAULT '',
    ip inet,
    failed_count integer NOT NULL DEFAULT 0,
    first_failed_at timestamp with time zone NOT NULL DEFAULT NOW(),
    last_failed_at timestamp with time zone NOT NULL DEFAULT NOW(),
    blocked_until timestamp with time zone,
    updated_at timestamp with time zone NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_auth_login_throttle_blocked_until
    ON auth_login_throttle (blocked_until);

CREATE INDEX IF NOT EXISTS idx_auth_login_throttle_last_failed_at
    ON auth_login_throttle (last_failed_at);

-- Rotate stored session tokens to non-reversible hash representation and
-- force fresh login for all currently active sessions.
UPDATE auth_sessions
SET token = encode(digest(token, 'sha256'), 'hex');

UPDATE auth_sessions
SET ended_at = NOW(),
    ended_reason = COALESCE(ended_reason, 'security_token_rotation')
WHERE ended_at IS NULL;

INSERT INTO permissions (id, code, title, category, created_at, updated_at)
VALUES (
    'a9a7f7f5-7f14-4e0f-a58b-3f6fca0cb29f',
    'system.vm_console.files.manage',
    'Manage VM console file transfer (upload/download/list)',
    'system',
    NOW(),
    NOW()
)
ON CONFLICT (code) DO UPDATE
SET
    title = EXCLUDED.title,
    category = EXCLUDED.category,
    updated_at = NOW();

INSERT INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p.id
FROM role_permissions rp
INNER JOIN permissions basep ON basep.id = rp.permission_id
INNER JOIN permissions p ON p.code = 'system.vm_console.files.manage'
WHERE basep.code = 'page.system.vm_console.view'
ON CONFLICT (role_id, permission_id) DO NOTHING;
