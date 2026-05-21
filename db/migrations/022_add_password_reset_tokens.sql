CREATE TABLE IF NOT EXISTS "PasswordResetToken" (
    id text PRIMARY KEY,
    tenant_id text NOT NULL,
    user_id text NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp without time zone NOT NULL,
    used_at timestamp without time zone,
    request_ip text,
    request_user_agent text,
    used_ip text,
    used_user_agent text,
    created_at timestamp without time zone DEFAULT NOW() NOT NULL,
    updated_at timestamp without time zone DEFAULT NOW() NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS "PasswordResetToken_tenant_hash_uidx"
    ON "PasswordResetToken" (tenant_id, token_hash);

CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_user_idx"
    ON "PasswordResetToken" (tenant_id, user_id, created_at DESC);

CREATE INDEX IF NOT EXISTS "PasswordResetToken_tenant_expires_idx"
    ON "PasswordResetToken" (tenant_id, expires_at);
