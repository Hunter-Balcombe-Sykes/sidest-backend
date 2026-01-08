-- 1) Align bucket defaults to existing bucket "media"
alter table core.professionals alter column icon_bucket     set default 'media';
alter table core.professionals alter column headshot_bucket set default 'media';
alter table core.sites         alter column banner_bucket   set default 'media';
alter table core.site_images   alter column bucket          set default 'media';

-- 2) Soft-delete friendly unique indexes on core.blocks
drop index if exists core.blocks_links_site_group_sort_uq;
create unique index blocks_links_site_group_sort_uq
  on core.blocks (site_id, block_group, sort_order)
  where block_group = 'links' and deleted_at is null;

drop index if exists core.blocks_sections_site_group_sort_uq;
create unique index blocks_sections_site_group_sort_uq
  on core.blocks (site_id, block_group, sort_order)
  where block_group = 'sections' and deleted_at is null;

drop index if exists core.blocks_sections_site_group_type_uq;
create unique index blocks_sections_site_group_type_uq
  on core.blocks (site_id, block_group, block_type)
  where block_group = 'sections' and deleted_at is null;

-- 3) RLS: only staff can hard-delete blocks
drop policy if exists link_blocks_delete_authenticated on core.blocks;
create policy link_blocks_delete_staff_only
  on core.blocks for delete
  to authenticated
  using (exists (select 1 from core.comet_staff cs where cs.auth_user_id = auth.uid()));

-- 4) RLS: allow inserts into analytics.lead_submissions from public site and staff
create policy lead_submissions_public_insert
  on analytics.lead_submissions for insert
  to anon
  with check (
    exists (
      select 1
      from core.sites s
      where s.id = lead_submissions.site_id
        and s.professional_id = lead_submissions.professional_id
        and s.is_published = true
    )
  );

create policy lead_submissions_staff_all
  on analytics.lead_submissions for all
  to authenticated
  using (exists (select 1 from core.comet_staff cs where cs.auth_user_id = auth.uid()))
  with check (exists (select 1 from core.comet_staff cs where cs.auth_user_id = auth.uid()));

-- 5) Guard rail triggers for NOT NULL columns that default to NULL
-- Email subscriptions: ensure email_lc and unsubscribe_token are populated
create or replace function core.set_email_subscription_defaults()
returns trigger language plpgsql as $$
begin
  if new.email is not null then
    new.email_lc := lower(new.email);
  end if;
  if new.unsubscribe_token is null then
    new.unsubscribe_token := encode(gen_random_bytes(16), 'hex');
  end if;
  return new;
end;
$$;

drop trigger if exists set_email_subscription_defaults_insupd on core.email_subscriptions;
create trigger set_email_subscription_defaults_insupd
before insert or update on core.email_subscriptions
for each row execute function core.set_email_subscription_defaults();

-- Professionals: ensure handle_lc and qr_slug are populated
create or replace function core.set_professional_defaults()
returns trigger language plpgsql as $$
begin
  if new.handle is not null then
    new.handle_lc := lower(new.handle);
  end if;
  if new.qr_slug is null then
    new.qr_slug := encode(gen_random_bytes(16), 'hex');
  end if;
  return new;
end;
$$;

drop trigger if exists set_professional_defaults_insupd on core.professionals;
create trigger set_professional_defaults_insupd
before insert or update on core.professionals
for each row execute function core.set_professional_defaults();