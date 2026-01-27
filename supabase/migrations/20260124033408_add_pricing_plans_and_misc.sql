-- 20260124xxxxxx_billing.sql

-- (Usually already enabled on Supabase, but harmless to ensure)
create extension if not exists pgcrypto;

create schema if not exists billing;

-- ----------------------------
-- billing.plans
-- ----------------------------
create table if not exists billing.plans (
  id uuid primary key default gen_random_uuid(),

  plan_key text not null unique,          -- free / pro / elite
  name text not null,
  stripe_price_id text not null unique,   -- price_...
  is_active boolean not null default true,
  sort_order integer not null default 0,

  -- Optional: store feature flags / limits
  -- e.g. {"custom_domain": true, "max_links": 50}
  entitlements jsonb,

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

-- ----------------------------
-- billing.subscriptions
-- ----------------------------
create table if not exists billing.subscriptions (
  id uuid primary key default gen_random_uuid(),

  professional_id uuid not null
    references core.professionals(id) on delete cascade,

  plan_id uuid not null
    references billing.plans(id) on delete restrict,

  provider text not null default 'stripe',

  stripe_customer_id text,
  stripe_subscription_id text unique,

  -- Stripe-ish statuses: trialing, active, past_due, canceled, unpaid, incomplete...
  status text not null,

  current_period_end timestamptz,
  cancel_at_period_end boolean not null default false,
  trial_ends_at timestamptz,
  ended_at timestamptz,

  provider_payload jsonb,

  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists billing_subscriptions_professional_id_idx
  on billing.subscriptions (professional_id);

create index if not exists billing_subscriptions_status_idx
  on billing.subscriptions (status);

-- Only one "current" subscription row per professional (ended_at IS NULL)
create unique index if not exists billing_one_current_sub_per_professional
  on billing.subscriptions (professional_id)
  where ended_at is null;

-- ----------------------------
-- updated_at triggers
-- ----------------------------
create or replace function billing.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

drop trigger if exists trg_billing_plans_updated_at on billing.plans;
create trigger trg_billing_plans_updated_at
before update on billing.plans
for each row execute function billing.set_updated_at();

drop trigger if exists trg_billing_subscriptions_updated_at on billing.subscriptions;
create trigger trg_billing_subscriptions_updated_at
before update on billing.subscriptions
for each row execute function billing.set_updated_at();

-- ----------------------------
-- OPTIONAL: seed example plans
-- (Replace stripe_price_id with your real Stripe Price IDs)
-- ----------------------------
insert into billing.plans (plan_key, name, stripe_price_id, sort_order, entitlements)
values
   ('free',  'Free',  'price_FREE_REPLACE_ME',  0, '{"custom_domain": false, "max_links": 10}'),
   ('pro',   'Pro',   'price_PRO_REPLACE_ME',   1, '{"custom_domain": false, "max_links": 50}'),
   ('elite', 'Elite', 'price_ELITE_REPLACE_ME', 2, '{"custom_domain": true,  "max_links": 999999}');

-- ----------------------------
-- RLS + Policies
-- Enable these only if you want Supabase client-side access to read plans/subscription.
-- If everything goes through Laravel, you can skip this section.
-- ----------------------------
alter table billing.plans enable row level security;
alter table billing.subscriptions enable row level security;

-- Plans: allow anyone to read active plans (for pricing page)
drop policy if exists "read active plans" on billing.plans;
create policy "read active plans"
on billing.plans
for select
to anon, authenticated
using (is_active = true);

-- Subscriptions: allow authenticated users to read their own subscription
-- (Assumes core.professionals.auth_user_id is the Supabase auth uid)
drop policy if exists "read own subscription" on billing.subscriptions;
create policy "read own subscription"
on billing.subscriptions
for select
to authenticated
using (
    exists (
        select 1
        from core.professionals p
        where p.id = billing.subscriptions.professional_id
            and p.auth_user_id = auth.uid()
            and p.deleted_at is null
   )
);

grant usage on schema billing to anon, authenticated;
grant select on billing.plans to anon, authenticated;
grant select on billing.subscriptions to authenticated;

