<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
create or replace view core.public_site_payload
with (security_invoker='on') as
select
  s.id as site_id,
  s.professional_id,
  s.subdomain,
  jsonb_build_object(
    'site', jsonb_build_object(
      'id', s.id,
      'subdomain', s.subdomain,
      'settings', s.settings,
      'is_published', s.is_published,
      'banner', jsonb_build_object('bucket', s.banner_bucket, 'path', s.banner_path),
      'gallery', coalesce((
        select jsonb_agg(
          jsonb_build_object(
            'id', si.id,
            'bucket', si.bucket,
            'path', si.path,
            'alt_text', si.alt_text,
            'sort_order', si.sort_order
          )
          order by si.sort_order, si.created_at
        )
        from core.site_images si
        where si.site_id = s.id and si.deleted_at is null and si.is_active = true
      ), '[]'::jsonb)
    ),
    'professional', jsonb_build_object(
      'id', p.id,
      'handle', p.handle,
      'display_name', p.display_name,
      'bio', p.bio,
      'country_code', p.country_code,
      'timezone', p.timezone,
      'public_contact_number', p.public_contact_number,
      'public_contact_email', p.public_contact_email,
      'icon', jsonb_build_object('bucket', p.icon_bucket, 'path', p.icon_path),
      'headshot', jsonb_build_object('bucket', p.headshot_bucket, 'path', p.headshot_path)
    ),
    'theme', case when t.id is null then null::jsonb else jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config) end,
    'links', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        order by b.sort_order, b.created_at
      )
      from core.blocks b
      where b.site_id = s.id and b.block_group = 'links' and b.is_active = true and b.deleted_at is null
    ), '[]'::jsonb),
    'sections', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        order by b.sort_order, b.created_at
      )
      from core.blocks b
      where b.site_id = s.id and b.block_group = 'sections' and b.is_active = true and b.deleted_at is null
    ), '[]'::jsonb),
    'services', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', sv.id,
          'title', sv.title,
          'description', sv.description,
          'price_cents', sv.price_cents,
          'currency_code', sv.currency_code,
          'duration_minutes', sv.duration_minutes,
          'is_active', sv.is_active,
          'sort_order', sv.sort_order,
          'category', coalesce(sc.title, 'Services')
        )
        order by coalesce(sc.sort_order, 2147483647), lower(coalesce(sc.title, 'Services')), sv.sort_order, sv.created_at
      )
      from core.services sv
      left join core.service_categories sc
        on sc.id = sv.category_id
       and sc.deleted_at is null
      where sv.professional_id = p.id and sv.is_active = true and sv.deleted_at is null
    ), '[]'::jsonb)
  ) as payload
from core.sites s
join core.professionals p on p.id = s.professional_id
left join core.themes t on t.id = s.theme_id
where
  s.is_published = true
  and p.status = 'active'
  and p.deleted_at is null;
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
create or replace view core.public_site_payload
with (security_invoker='on') as
select
  s.id as site_id,
  s.professional_id,
  s.subdomain,
  jsonb_build_object(
    'site', jsonb_build_object(
      'id', s.id,
      'subdomain', s.subdomain,
      'settings', s.settings,
      'is_published', s.is_published,
      'banner', jsonb_build_object('bucket', s.banner_bucket, 'path', s.banner_path),
      'gallery', coalesce((
        select jsonb_agg(
          jsonb_build_object(
            'id', si.id,
            'bucket', si.bucket,
            'path', si.path,
            'alt_text', si.alt_text,
            'sort_order', si.sort_order
          )
          order by si.sort_order, si.created_at
        )
        from core.site_images si
        where si.site_id = s.id and si.deleted_at is null and si.is_active = true
      ), '[]'::jsonb)
    ),
    'professional', jsonb_build_object(
      'id', p.id,
      'handle', p.handle,
      'display_name', p.display_name,
      'bio', p.bio,
      'country_code', p.country_code,
      'timezone', p.timezone,
      'public_contact_number', p.public_contact_number,
      'public_contact_email', p.public_contact_email,
      'icon', jsonb_build_object('bucket', p.icon_bucket, 'path', p.icon_path),
      'headshot', jsonb_build_object('bucket', p.headshot_bucket, 'path', p.headshot_path)
    ),
    'theme', case when t.id is null then null::jsonb else jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config) end,
    'links', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        order by b.sort_order, b.created_at
      )
      from core.blocks b
      where b.site_id = s.id and b.block_group = 'links' and b.is_active = true and b.deleted_at is null
    ), '[]'::jsonb),
    'sections', coalesce((
      select jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        order by b.sort_order, b.created_at
      )
      from core.blocks b
      where b.site_id = s.id and b.block_group = 'sections' and b.is_active = true and b.deleted_at is null
    ), '[]'::jsonb)
  ) as payload
from core.sites s
join core.professionals p on p.id = s.professional_id
left join core.themes t on t.id = s.theme_id
where
  s.is_published = true
  and p.status = 'active'
  and p.deleted_at is null;
SQL);
    }
};
