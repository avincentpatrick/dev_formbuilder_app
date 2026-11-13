-- Private document library. Existing corpus IDs and chunks remain unchanged.
create table document_catalog (
  id uuid primary key default gen_random_uuid(),
  title text not null,
  issuer text,
  document_type text,
  issuance_number text,
  tags text[] not null default '{}',
  collection_key text,
  created_by uuid not null,
  created_at timestamptz not null default now()
);

-- Content is shared by exact duplicates. Only generated storage keys are accepted.
create table document_file (
  sha256 text primary key check (sha256 ~ '^[0-9a-f]{64}$'),
  storage_key text not null unique check (storage_key ~ '^[0-9a-f-]{36}\.pdf$'),
  byte_count bigint not null check (byte_count > 0 and byte_count <= 26214400),
  created_at timestamptz not null default now()
);
create table document_version (
  id uuid primary key default gen_random_uuid(),
  document_id uuid not null references document_catalog(id),
  file_sha256 text not null references document_file(sha256),
  publication_date date,
  effective_date date,
  supersedes_id uuid references document_version(id),
  archived boolean not null default false,
  availability text not null default 'unavailable' check (availability in ('unavailable','available','available_with_warnings')),
  embedding_status text not null default 'pending' check (embedding_status in ('pending','running','ready','failed','disabled')),
  uploaded_by uuid not null,
  uploaded_at timestamptz not null default now(),
  unique (document_id, file_sha256),
  check (supersedes_id is distinct from id)
);
create table document_extraction (
  id uuid primary key default gen_random_uuid(),
  version_id uuid not null references document_version(id),
  revision integer not null check (revision > 0),
  canonical_text text not null,
  page_map jsonb not null check (jsonb_typeof(page_map) = 'array'),
  page_count integer not null check (page_count between 1 and 300),
  parser_version text not null,
  ocr_version text,
  languages text[] not null default '{}',
  warnings jsonb not null default '[]' check (jsonb_typeof(warnings) = 'array'),
  -- One existing corpus source per immutable extraction, never replace old chunks.
  source_id bigint unique references doc_source(doc_id),
  activation_origin text check (activation_origin = 'successful_upload'),
  created_at timestamptz not null default now(),
  unique (version_id, revision),
  check (source_id is null or (length(canonical_text) > 0 and activation_origin is not null))
);
create table document_job (
  id uuid primary key default gen_random_uuid(),
  version_id uuid not null references document_version(id),
  requested_by uuid not null,
  stage text not null default 'queued' check (stage in ('queued','extracting','ocr','indexing','ready','ready_with_warnings','failed')),
  progress integer not null default 0 check (progress between 0 and 100),
  attempts integer not null default 0 check (attempts between 0 and 3),
  lease_token uuid,
  lease_until timestamptz,
  heartbeat_at timestamptz,
  retry_at timestamptz,
  safe_error text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index document_job_claim_idx on document_job(stage, retry_at, lease_until);
create index document_version_catalog_idx on document_version(document_id);
create index document_extraction_version_idx on document_extraction(version_id);
create table document_upload_attempt (
  id uuid primary key default gen_random_uuid(),
  uploaded_by uuid not null,
  original_filename text not null,
  detected_type text,
  byte_count bigint check (byte_count >= 0),
  sha256 text check (sha256 ~ '^[0-9a-f]{64}$'),
  outcome text not null check (outcome in ('receiving','rejected','duplicate','queued','failed')),
  duplicate_version_id uuid references document_version(id),
  version_id uuid references document_version(id),
  safe_reason text,
  created_at timestamptz not null default now()
);
create table document_audit_event (
  id bigint generated always as identity primary key,
  actor_id uuid not null,
  document_id uuid references document_catalog(id),
  version_id uuid references document_version(id),
  event text not null,
  details jsonb not null default '{}',
  created_at timestamptz not null default now()
);
-- No public policies; browser credentials cannot read metadata or text.
alter table document_catalog enable row level security;
alter table document_file enable row level security;
alter table document_version enable row level security;
alter table document_extraction enable row level security;
alter table document_job enable row level security;
alter table document_upload_attempt enable row level security;
alter table document_audit_event enable row level security;
revoke all on document_catalog, document_file, document_version, document_extraction,
  document_job, document_upload_attempt, document_audit_event from anon, authenticated;
grant all on document_catalog, document_file, document_version, document_extraction,
  document_job, document_upload_attempt, document_audit_event to service_role;
grant usage, select on sequence document_audit_event_id_seq to service_role;
