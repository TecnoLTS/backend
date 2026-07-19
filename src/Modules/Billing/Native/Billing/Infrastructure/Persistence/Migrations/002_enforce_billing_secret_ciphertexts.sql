-- Secret values are encrypted by the application with an authenticated,
-- versioned envelope. NOT VALID lets an existing installation add the write
-- barrier before the one-shot PHP migrator transforms legacy rows. New/fresh
-- rows are constrained immediately; the migrator validates both constraints
-- atomically after all historical values have been converted.
ALTER TABLE public.client_branches
    ADD CONSTRAINT client_branches_certificate_password_ciphertext_check
    CHECK (
        octet_length(certificate_password) BETWEEN 16 AND 16384
        AND certificate_password ~ '^pmbillenc:v1:[A-Za-z0-9_-]+$'
    ) NOT VALID;

ALTER TABLE public.client_branches
    ADD CONSTRAINT client_branches_mail_password_ciphertext_check
    CHECK (
        mail_password IS NULL
        OR (
            octet_length(mail_password) BETWEEN 16 AND 16384
            AND mail_password ~ '^pmbillenc:v1:[A-Za-z0-9_-]+$'
        )
    ) NOT VALID;

COMMENT ON COLUMN public.client_branches.certificate_password IS
    'Authenticated pmbillenc:v1 ciphertext; plaintext is prohibited.';
COMMENT ON COLUMN public.client_branches.mail_password IS
    'Authenticated pmbillenc:v1 ciphertext or NULL; plaintext is prohibited.';
