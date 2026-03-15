CREATE TABLE IF NOT EXISTS clouds (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    pnet VARCHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT clouds_name_pnet_uniq UNIQUE (name, pnet),
    CONSTRAINT clouds_pnet_format_chk CHECK (pnet ~ '^pnet[0-9]+$')
);

CREATE INDEX IF NOT EXISTS idx_clouds_pnet ON clouds (pnet);
