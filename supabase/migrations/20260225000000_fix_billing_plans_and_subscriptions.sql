-- Fix missing columns on billing.plans and billing.subscriptions

-- ----------------------------
-- billing.plans: add price_cents, currency_code, billing_interval, description
-- ----------------------------
alter table billing.plans
  add column if not exists description text,
  add column if not exists price_cents integer not null default 0,
  add column if not exists currency_code text not null default 'AUD',
  add column if not exists billing_interval text not null default 'month';

comment on column billing.plans.price_cents is 'Price in the smallest currency unit (e.g. cents)';
comment on column billing.plans.currency_code is 'ISO 4217 currency code';
comment on column billing.plans.billing_interval is 'Billing cadence: month | year';

-- Back-fill the seed rows if they already exist
update billing.plans set price_cents = 0,     billing_interval = 'month' where plan_key = 'free'  and price_cents = 0;
update billing.plans set price_cents = 2900,  billing_interval = 'month' where plan_key = 'pro'   and price_cents = 0;
update billing.plans set price_cents = 5900,  billing_interval = 'month' where plan_key = 'elite' and price_cents = 0;

-- ----------------------------
-- billing.subscriptions: add current_period_start
-- ----------------------------
alter table billing.subscriptions
  add column if not exists current_period_start timestamptz;
