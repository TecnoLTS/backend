CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $set_updated_at$
BEGIN
    NEW.updated_at := NOW();
    RETURN NEW;
END
$set_updated_at$;

CREATE TABLE IF NOT EXISTS public.clients (
    id bigserial PRIMARY KEY,
    ruc varchar(13) NOT NULL,
    business_name varchar(255) NOT NULL,
    trade_name varchar(255),
    phone varchar(30),
    email varchar(255),
    address text,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT clients_ruc_key UNIQUE (ruc)
);

CREATE TABLE IF NOT EXISTS public.client_branches (
    id bigserial PRIMARY KEY,
    client_id bigint NOT NULL,
    code varchar(3) NOT NULL,
    emission_point varchar(3) NOT NULL DEFAULT '001',
    branch_name varchar(255),
    address text NOT NULL,
    logo_path text,
    certificate_path text NOT NULL,
    certificate_password text NOT NULL,
    mail_enabled boolean,
    mail_host varchar(255),
    mail_port integer,
    mail_encryption varchar(20),
    mail_username varchar(255),
    mail_password text,
    mail_from_address varchar(255),
    mail_from_name varchar(255),
    reply_to_address varchar(255),
    reply_to_name varchar(255),
    api_test boolean NOT NULL DEFAULT true,
    api_produccion boolean NOT NULL DEFAULT false,
    reintentos_test boolean NOT NULL DEFAULT true,
    reintentos_produccion boolean NOT NULL DEFAULT false,
    is_default boolean NOT NULL DEFAULT false,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_client_branch UNIQUE (client_id, code, emission_point),
    CONSTRAINT client_branches_client_id_fkey
        FOREIGN KEY (client_id) REFERENCES public.clients (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public.billing_customers (
    id bigserial PRIMARY KEY,
    tenant_id text NOT NULL,
    identification text NOT NULL,
    name text NOT NULL,
    email text,
    address text,
    source text NOT NULL DEFAULT 'invoice_headers',
    first_seen_at timestamptz NOT NULL DEFAULT NOW(),
    last_seen_at timestamptz NOT NULL DEFAULT NOW(),
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT billing_customers_tenant_id_identification_key
        UNIQUE (tenant_id, identification)
);

-- A historical QA bootstrap assigned every omitted customer to one tenant.
-- Tenant ownership is always explicit in the native Billing repository.
ALTER TABLE public.billing_customers ALTER COLUMN tenant_id DROP DEFAULT;

CREATE TABLE IF NOT EXISTS public.api_keys (
    id bigserial PRIMARY KEY,
    client_id bigint NOT NULL,
    branch_id bigint,
    name varchar(120) NOT NULL,
    key_prefix varchar(24) NOT NULL,
    key_hash varchar(64) NOT NULL,
    last_used_at timestamptz,
    revoked_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT api_keys_key_hash_key UNIQUE (key_hash),
    CONSTRAINT api_keys_client_id_fkey
        FOREIGN KEY (client_id) REFERENCES public.clients (id) ON DELETE CASCADE,
    CONSTRAINT api_keys_branch_id_fkey
        FOREIGN KEY (branch_id) REFERENCES public.client_branches (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS public.invoice_retry_settings (
    id bigserial PRIMARY KEY,
    tenant_id text,
    ambiente varchar(20) NOT NULL,
    max_retry_days integer NOT NULL,
    max_attempts integer NOT NULL DEFAULT 3,
    delay_seconds integer NOT NULL DEFAULT 3600,
    is_active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_invoice_retry_settings_ambiente
        CHECK (ambiente IN ('pruebas', 'produccion')),
    CONSTRAINT invoice_retry_settings_max_retry_days_check CHECK (max_retry_days >= 0),
    CONSTRAINT chk_invoice_retry_settings_max_attempts_positive CHECK (max_attempts >= 1),
    CONSTRAINT chk_invoice_retry_settings_delay_seconds_minimum CHECK (delay_seconds >= 3600)
);

ALTER TABLE public.invoice_retry_settings
    ADD COLUMN IF NOT EXISTS tenant_id text;

CREATE TABLE IF NOT EXISTS public.branch_sequences (
    branch_id bigint NOT NULL,
    ambiente varchar(20) NOT NULL,
    current_value bigint NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT branch_sequences_pkey PRIMARY KEY (branch_id, ambiente),
    CONSTRAINT branch_sequences_branch_id_fkey
        FOREIGN KEY (branch_id) REFERENCES public.client_branches (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public.invoice_headers (
    id bigserial PRIMARY KEY,
    client_id bigint NOT NULL,
    branch_id bigint,
    api_key_id bigint,
    source_reference varchar(120),
    access_key varchar(49) NOT NULL,
    authorization_number varchar(64),
    authorization_date timestamptz,
    issue_date date NOT NULL,
    customer_name varchar(255) NOT NULL,
    customer_identification varchar(20) NOT NULL,
    customer_email varchar(255),
    customer_address text,
    subtotal_without_tax numeric(14,2) NOT NULL DEFAULT 0,
    total_tax numeric(14,2) NOT NULL DEFAULT 0,
    total_with_tax numeric(14,2) NOT NULL DEFAULT 0,
    payment_method_code varchar(2),
    payment_method_label varchar(255),
    establishment_code varchar(3) NOT NULL,
    emission_point varchar(3) NOT NULL,
    sequential varchar(9) NOT NULL,
    ambiente varchar(20),
    sri_status varchar(40) NOT NULL,
    sri_messages jsonb,
    raw_request jsonb,
    raw_response jsonb,
    signed_xml_path text,
    authorized_xml_path text,
    cancelled_at timestamptz,
    cancellation_reason text,
    replacement_access_key varchar(49),
    replaced_access_key varchar(49),
    reintento boolean NOT NULL DEFAULT false,
    intentos integer NOT NULL DEFAULT 0,
    authorized_xml_received boolean NOT NULL DEFAULT false,
    mail_sent_at timestamptz,
    last_sri_check_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    updated_at timestamptz NOT NULL DEFAULT NOW(),
    billing_customer_id bigint,
    CONSTRAINT invoice_headers_access_key_key UNIQUE (access_key),
    CONSTRAINT chk_invoice_headers_intentos_non_negative CHECK (intentos >= 0),
    CONSTRAINT invoice_headers_client_id_fkey
        FOREIGN KEY (client_id) REFERENCES public.clients (id) ON DELETE RESTRICT,
    CONSTRAINT invoice_headers_branch_id_fkey
        FOREIGN KEY (branch_id) REFERENCES public.client_branches (id) ON DELETE SET NULL,
    CONSTRAINT invoice_headers_api_key_id_fkey
        FOREIGN KEY (api_key_id) REFERENCES public.api_keys (id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS public.invoice_details (
    id bigserial PRIMARY KEY,
    invoice_header_id bigint NOT NULL,
    line_number integer NOT NULL,
    product_code varchar(100),
    auxiliary_code varchar(100),
    description text NOT NULL,
    additional_detail text,
    quantity numeric(18,6) NOT NULL,
    unit_price numeric(14,6) NOT NULL,
    discount numeric(18,6) NOT NULL DEFAULT 0,
    line_subtotal numeric(18,6) NOT NULL,
    tax_amount numeric(18,6) NOT NULL DEFAULT 0,
    tax_rate numeric(6,2) NOT NULL DEFAULT 15,
    created_at timestamptz NOT NULL DEFAULT NOW(),
    CONSTRAINT invoice_details_invoice_header_id_fkey
        FOREIGN KEY (invoice_header_id) REFERENCES public.invoice_headers (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS public.billing_domain_events (
    event_id text PRIMARY KEY,
    tenant_id text,
    event_name text NOT NULL,
    access_key text,
    client_id bigint,
    branch_id bigint,
    api_key_id bigint,
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    occurred_on timestamp without time zone NOT NULL,
    recorded_at timestamp without time zone NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_client_branches_client_id
    ON public.client_branches (client_id);
CREATE INDEX IF NOT EXISTS idx_api_keys_client_id
    ON public.api_keys (client_id);
CREATE INDEX IF NOT EXISTS idx_invoice_retry_settings_ambiente
    ON public.invoice_retry_settings (tenant_id, ambiente);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_client_id
    ON public.invoice_headers (client_id);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_branch_id
    ON public.invoice_headers (branch_id);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_status
    ON public.invoice_headers (sri_status);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_reintento
    ON public.invoice_headers (reintento);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_authorized_xml_received
    ON public.invoice_headers (authorized_xml_received);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_replacement_access_key
    ON public.invoice_headers (replacement_access_key);
CREATE INDEX IF NOT EXISTS idx_invoice_headers_replaced_access_key
    ON public.invoice_headers (replaced_access_key);
CREATE INDEX IF NOT EXISTS invoice_headers_billing_customer_idx
    ON public.invoice_headers (billing_customer_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_invoice_headers_one_authorized_active_per_source
    ON public.invoice_headers (client_id, branch_id, source_reference)
    WHERE source_reference IS NOT NULL
      AND BTRIM(source_reference) <> ''
      AND cancelled_at IS NULL
      AND replacement_access_key IS NULL
      AND UPPER(COALESCE(sri_status, '')) = 'AUTORIZADO';
CREATE INDEX IF NOT EXISTS idx_invoice_details_header_id
    ON public.invoice_details (invoice_header_id);
CREATE INDEX IF NOT EXISTS billing_customers_tenant_name_idx
    ON public.billing_customers (tenant_id, name);
CREATE INDEX IF NOT EXISTS billing_domain_events_access_key_idx
    ON public.billing_domain_events (access_key, occurred_on DESC);
CREATE INDEX IF NOT EXISTS billing_domain_events_client_event_idx
    ON public.billing_domain_events (client_id, event_name, occurred_on DESC);

DROP TRIGGER IF EXISTS trg_clients_updated_at ON public.clients;
CREATE TRIGGER trg_clients_updated_at
BEFORE UPDATE ON public.clients
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_client_branches_updated_at ON public.client_branches;
CREATE TRIGGER trg_client_branches_updated_at
BEFORE UPDATE ON public.client_branches
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_invoice_headers_updated_at ON public.invoice_headers;
CREATE TRIGGER trg_invoice_headers_updated_at
BEFORE UPDATE ON public.invoice_headers
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

DROP TRIGGER IF EXISTS trg_invoice_retry_settings_updated_at ON public.invoice_retry_settings;
CREATE TRIGGER trg_invoice_retry_settings_updated_at
BEFORE UPDATE ON public.invoice_retry_settings
FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
