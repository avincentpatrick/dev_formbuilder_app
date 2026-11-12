-- Approval is independent of administrator status and Meridian identity.
create table public.analysis_users (
  user_id uuid primary key,
  approved boolean not null default false,
  updated_at timestamptz not null default now(),
  updated_by uuid not null
);
alter table public.analysis_users enable row level security;
revoke all on public.analysis_users from anon, authenticated;
grant select, insert, update on public.analysis_users to service_role;
comment on table public.analysis_users is 'Dashboard analysis approval. Server authorization required; no automatic admin bypass.';
