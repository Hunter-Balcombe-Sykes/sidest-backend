-- 1) Ensure the public-assets bucket exists
insert into storage.buckets (id, name, public, type, created_at, updated_at)
values ('public-assets', 'public-assets', true, 'STANDARD', now(), now())
on conflict (id) do nothing;

-- 2) Make public-assets the default everywhere images are stored
alter table core.professionals alter column icon_bucket     set default 'public-assets';
alter table core.professionals alter column headshot_bucket set default 'public-assets';
alter table core.sites         alter column banner_bucket   set default 'public-assets';
alter table core.site_images   alter column bucket          set default 'public-assets';

-- 3) Backfill any NULL buckets to public-assets (idempotent)
update core.professionals set icon_bucket     = 'public-assets' where icon_bucket     is null;
update core.professionals set headshot_bucket = 'public-assets' where headshot_bucket is null;
update core.sites         set banner_bucket   = 'public-assets' where banner_bucket   is null;
update core.site_images   set bucket          = 'public-assets' where bucket          is null;

-- 4) Ensure email_subscriptions gets email_lc + unsubscribe_token automatically
do $$
begin
  if not exists (
    select 1 from pg_trigger t
    join pg_class c on c.oid = t.tgrelid
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'core' and c.relname = 'email_subscriptions' and t.tgname = 'set_email_subscription_defaults_trg'
  ) then
    create trigger set_email_subscription_defaults_trg
    before insert or update on core.email_subscriptions
    for each row execute function core.set_email_subscription_defaults();
  end if;
end$$;

-- 5) Ensure professionals gets handle_lc + qr_slug automatically
do $$
begin
  if not exists (
    select 1 from pg_trigger t
    join pg_class c on c.oid = t.tgrelid
    join pg_namespace n on n.oid = c.relnamespace
    where n.nspname = 'core' and c.relname = 'professionals' and t.tgname = 'set_professional_defaults_trg'
  ) then
    create trigger set_professional_defaults_trg
    before insert or update on core.professionals
    for each row execute function core.set_professional_defaults();
  end if;
end$$;