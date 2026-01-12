grant usage on schema core to authenticated, anon;

grant select (id, auth_user_id, status, deleted_at)
on core.professionals
to authenticated, anon;

grant select (id, professional_id, is_published)
on core.sites
to authenticated, anon;

-- Optional: only keep this if you know your migration role can alter it.
-- In many Supabase projects RLS is already enabled on storage.objects.
-- alter table storage.objects enable row level security;

-- AUTH: READ own objects (needed for upsert flows)
drop policy if exists "auth read own assets" on storage.objects;
create policy "auth read own assets"
on storage.objects
for select
to authenticated
using (
  bucket_id = 'public-assets'
  and auth.uid() is not null
  and owner_id = (select auth.uid()::text)
  and (
    (
      split_part(name,'/',1) = 'professionals'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.professionals p
        where p.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
    or
    (
      split_part(name,'/',1) = 'sites'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.sites s
        join core.professionals p on p.id = s.professional_id
        where s.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
  )
);

-- AUTH: INSERT into own professional folder
drop policy if exists "auth upload own professional assets" on storage.objects;
create policy "auth upload own professional assets"
on storage.objects
for insert
to authenticated
with check (
  bucket_id = 'public-assets'
  and auth.uid() is not null
  and owner_id = (select auth.uid()::text)
  and split_part(name,'/',1) = 'professionals'
  and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
  and exists (
    select 1
    from core.professionals p
    where p.id = split_part(name,'/',2)::uuid
      and p.auth_user_id = auth.uid()
      and p.deleted_at is null
  )
);

-- AUTH: INSERT into own site folder
drop policy if exists "auth upload own site assets" on storage.objects;
create policy "auth upload own site assets"
on storage.objects
for insert
to authenticated
with check (
  bucket_id = 'public-assets'
  and auth.uid() is not null
  and owner_id = (select auth.uid()::text)
  and split_part(name,'/',1) = 'sites'
  and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
  and exists (
    select 1
    from core.sites s
    join core.professionals p on p.id = s.professional_id
    where s.id = split_part(name,'/',2)::uuid
      and p.auth_user_id = auth.uid()
      and p.deleted_at is null
  )
);

-- AUTH: UPDATE (overwrite) own assets
drop policy if exists "auth update own assets" on storage.objects;
create policy "auth update own assets"
on storage.objects
for update
to authenticated
using (
  bucket_id = 'public-assets'
  and auth.uid() is not null
  and owner_id = (select auth.uid()::text)
  and (
    (
      split_part(name,'/',1) = 'professionals'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.professionals p
        where p.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
    or
    (
      split_part(name,'/',1) = 'sites'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.sites s
        join core.professionals p on p.id = s.professional_id
        where s.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
  )
)
with check (
  bucket_id = 'public-assets'
  and auth.uid() is not null
  and owner_id = (select auth.uid()::text)
  and (
    (
      split_part(name,'/',1) = 'professionals'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.professionals p
        where p.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
    or
    (
      split_part(name,'/',1) = 'sites'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.sites s
        join core.professionals p on p.id = s.professional_id
        where s.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
  )
);

-- AUTH: DELETE own assets
drop policy if exists "auth delete own assets" on storage.objects;
create policy "auth delete own assets"
on storage.objects
for delete
to authenticated
using (
  bucket_id = 'public-assets'
  and auth.uid() is not null
  and owner_id = (select auth.uid()::text)
  and (
    (
      split_part(name,'/',1) = 'professionals'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.professionals p
        where p.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
    or
    (
      split_part(name,'/',1) = 'sites'
      and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
      and exists (
        select 1
        from core.sites s
        join core.professionals p on p.id = s.professional_id
        where s.id = split_part(name,'/',2)::uuid
          and p.auth_user_id = auth.uid()
          and p.deleted_at is null
      )
    )
  )
);

-- PUBLIC: read site assets only when published + pro active
drop policy if exists "public read published site assets" on storage.objects;
create policy "public read published site assets"
on storage.objects
for select
to anon, authenticated
using (
  bucket_id = 'public-assets'
  and split_part(name,'/',1) = 'sites'
  and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
  and exists (
    select 1
    from core.sites s
    join core.professionals p on p.id = s.professional_id
    where s.id = split_part(name,'/',2)::uuid
      and s.is_published = true
      and p.status = 'active'
      and p.deleted_at is null
  )
);

-- PUBLIC: read professional assets only when they have a published site + active
drop policy if exists "public read professional assets when published" on storage.objects;
create policy "public read professional assets when published"
on storage.objects
for select
to anon, authenticated
using (
  bucket_id = 'public-assets'
  and split_part(name,'/',1) = 'professionals'
  and split_part(name,'/',2) ~* '^[0-9a-f-]{36}$'
  and exists (
    select 1
    from core.professionals p
    join core.sites s on s.professional_id = p.id
    where p.id = split_part(name,'/',2)::uuid
      and p.status = 'active'
      and p.deleted_at is null
      and s.is_published = true
  )
);
