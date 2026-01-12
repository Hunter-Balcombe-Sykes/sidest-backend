-- Comet Stage 1–2: Squashed initial schema
-- Generated: 2026-01-09
-- Source: combined from:
--   1) 20260108062104_baseline.sql
--   2) 20260108081832_bug_fixes_for_locallity_change.sql
--   3) 20260108091003_bug_fixes_again_for_change.sql
--   4) 20260108091806_more_fixes_before.sql
--   5) 20260108100309_further_bug_fixes.sql
--
-- Notes:
--   - Policies / RLS / grants are preserved exactly as in the source migrations.
--   - Removed Laravel infrastructure tables in the public schema that are not needed for this project’s DB state:
--       public.cache, public.cache_locks, public.jobs, public.migrations,
--       public.password_reset_tokens, public.sessions, public.users
--   - Kept public.failed_jobs and public.job_batches (safe even if unused).
--



SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;


CREATE SCHEMA IF NOT EXISTS "analytics";


ALTER SCHEMA "analytics" OWNER TO "postgres";


CREATE SCHEMA IF NOT EXISTS "core";


ALTER SCHEMA "core" OWNER TO "postgres";


COMMENT ON SCHEMA "public" IS 'standard public schema';



CREATE EXTENSION IF NOT EXISTS "pg_graphql" WITH SCHEMA "graphql";






CREATE EXTENSION IF NOT EXISTS "pg_stat_statements" WITH SCHEMA "extensions";






CREATE EXTENSION IF NOT EXISTS "pgcrypto" WITH SCHEMA "extensions";






CREATE EXTENSION IF NOT EXISTS "supabase_vault" WITH SCHEMA "vault";






CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA "extensions";






CREATE OR REPLACE FUNCTION "core"."comet_schema_report"("include_schemas" "text"[] DEFAULT NULL::"text"[], "exclude_schemas" "text"[] DEFAULT ARRAY['auth'::"text", 'extensions'::"text", 'graphql'::"text", 'graphql_public'::"text", 'pgbouncer'::"text", 'realtime'::"text", 'vault'::"text", 'information_schema'::"text", 'pg_catalog'::"text"]) RETURNS "jsonb"
    LANGUAGE "plpgsql" STABLE
    SET "search_path" TO 'pg_catalog'
    AS $_$
declare
  base jsonb;
  storage_buckets jsonb := '[]'::jsonb;

  include_storage boolean;

  -- buckets columns may vary across storage versions, so detect safely
  has_id boolean;
  has_name boolean;
  has_public boolean;
  has_file_size_limit boolean;
  has_allowed_mime_types boolean;
  has_owner_id boolean;
  has_owner boolean;
  has_created_at boolean;
  has_updated_at boolean;
  has_type boolean;

  has_objects boolean;

  bucket_expr text;
  join_clause text;
  sql text;
  order_expr text;
begin
  -- ===== Base schema report (your original SQL) =====
  with included_schemas as (
    select n.nspname
    from pg_namespace n
    where
      (
        include_schemas is null
        and n.nspname !~ '^pg_'                      -- exclude pg_* internal schemas
        and not (n.nspname = any(exclude_schemas))   -- exclude supabase/system schemas
      )
      or (
        include_schemas is not null
        and n.nspname = any(include_schemas)
      )
  ),
  rels as (
    select
      n.nspname as schema,
      c.relname as name,
      c.oid as oid,
      c.relkind as relkind,
      case c.relkind
        when 'r' then 'table'
        when 'p' then 'partitioned_table'
        when 'v' then 'view'
        when 'm' then 'materialized_view'
        when 'f' then 'foreign_table'
        else c.relkind::text
      end as type,
      c.relrowsecurity as rls_enabled,
      c.relforcerowsecurity as rls_forced,
      pg_total_relation_size(c.oid) as total_bytes,
      pg_relation_size(c.oid) as heap_bytes,
      pg_indexes_size(c.oid) as index_bytes,
      coalesce(st.n_live_tup, null) as live_rows_est
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
    join included_schemas s on s.nspname = n.nspname
    left join pg_stat_user_tables st on st.relid = c.oid
    where c.relkind in ('r','p','v','m','f')
  ),
  report_tables as (
    select jsonb_agg(
      jsonb_build_object(
        'schema', r.schema,
        'name', r.name,
        'type', r.type,
        'size_bytes', jsonb_build_object(
          'total', r.total_bytes,
          'heap', r.heap_bytes,
          'indexes', r.index_bytes
        ),
        'live_rows_est', r.live_rows_est,
        'rls', jsonb_build_object(
          'enabled', r.rls_enabled,
          'forced', r.rls_forced,
          'policies', (
            select coalesce(jsonb_agg(pol order by pol->>'name'), '[]'::jsonb)
            from (
              select jsonb_build_object(
                'name', p.polname,
                'command', case p.polcmd
                  when 'r' then 'SELECT'
                  when 'a' then 'INSERT'
                  when 'w' then 'UPDATE'
                  when 'd' then 'DELETE'
                  else p.polcmd::text
                end,
                'roles', (
                  case
                    when p.polroles = '{0}'::oid[] then '["PUBLIC"]'::jsonb
                    when p.polroles is null or array_length(p.polroles, 1) is null then '["PUBLIC"]'::jsonb
                    else (
                      select jsonb_agg(pr.rolname order by pr.rolname)
                      from unnest(p.polroles) roid
                      join pg_roles pr on pr.oid = roid
                    )
                  end
                ),
                'using', pg_get_expr(p.polqual, p.polrelid),
                'with_check', pg_get_expr(p.polwithcheck, p.polrelid)
              ) as pol
              from pg_policy p
              where p.polrelid = r.oid
            ) x
          )
        ),
        'columns', (
          select coalesce(jsonb_agg(
            jsonb_build_object(
              'name', a.attname,
              'type', format_type(a.atttypid, a.atttypmod),
              'not_null', a.attnotnull,
              'default', pg_get_expr(ad.adbin, ad.adrelid),
              'identity', nullif(a.attidentity, ''),
              'generated', nullif(a.attgenerated, '')
            )
            order by a.attnum
          ), '[]'::jsonb)
          from pg_attribute a
          left join pg_attrdef ad
            on ad.adrelid = a.attrelid and ad.adnum = a.attnum
          where a.attrelid = r.oid and a.attnum > 0 and not a.attisdropped
        ),
        'grants', (
          select coalesce(jsonb_object_agg(grantee, privileges), '{}'::jsonb)
          from (
            select
              tp.grantee,
              jsonb_agg(distinct tp.privilege_type order by tp.privilege_type) as privileges
            from information_schema.table_privileges tp
            where tp.table_schema = r.schema and tp.table_name = r.name
            group by tp.grantee
          ) g
        ),
        'indexes', (
          select coalesce(jsonb_agg(
            jsonb_build_object(
              'name', i.relname,
              'is_unique', ix.indisunique,
              'is_primary', ix.indisprimary,
              'definition', pg_get_indexdef(i.oid)
            )
            order by i.relname
          ), '[]'::jsonb)
          from pg_index ix
          join pg_class i on i.oid = ix.indexrelid
          where ix.indrelid = r.oid
        ),
        'triggers', (
          select coalesce(jsonb_agg(
            jsonb_build_object(
              'name', t.tgname,
              'enabled', t.tgenabled,
              'definition', pg_get_triggerdef(t.oid, true)
            )
            order by t.tgname
          ), '[]'::jsonb)
          from pg_trigger t
          where t.tgrelid = r.oid and not t.tgisinternal
        )
      )
      order by r.schema, r.name
    ) as tables
    from rels r
  ),
  report_functions as (
    select coalesce(jsonb_agg(
      jsonb_build_object(
        'schema', n.nspname,
        'name', p.proname,
        'identity_args', pg_get_function_identity_arguments(p.oid),
        'language', l.lanname,
        'security_definer', p.prosecdef,
        'search_path_set', exists (
          select 1
          from unnest(coalesce(p.proconfig, array[]::text[])) cfg
          where cfg like 'search_path=%'
        ),
        'definition', pg_get_functiondef(p.oid)
      )
      order by n.nspname, p.proname
    ), '[]'::jsonb) as functions
    from pg_proc p
    join pg_namespace n on n.oid = p.pronamespace
    join pg_language l on l.oid = p.prolang
    join included_schemas s on s.nspname = n.nspname
  )
  select jsonb_build_object(
    'generated_at', now(),
    'schemas', (select jsonb_agg(nspname order by nspname) from included_schemas),
    'tables_and_views', (select tables from report_tables),
    'functions', (select functions from report_functions)
  )
  into base;

  -- ===== Storage buckets (rows in storage.buckets) =====
  include_storage := (include_schemas is null) or ('storage' = any(include_schemas));

  if include_storage and to_regclass('storage.buckets') is not null then
    -- detect bucket table columns
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='id') into has_id;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='name') into has_name;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='public') into has_public;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='file_size_limit') into has_file_size_limit;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='allowed_mime_types') into has_allowed_mime_types;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='owner_id') into has_owner_id;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='owner') into has_owner;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='created_at') into has_created_at;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='updated_at') into has_updated_at;
    select exists (select 1 from information_schema.columns where table_schema='storage' and table_name='buckets' and column_name='type') into has_type;

    has_objects := to_regclass('storage.objects') is not null;

    -- choose a stable ordering
    order_expr := case
      when has_id then 'b.id'
      when has_name then 'b.name'
      else '1'
    end;

    -- build jsonb object expression dynamically (so missing cols won't break the function)
    bucket_expr := 'jsonb_build_object(';

    if has_id then
      bucket_expr := bucket_expr || quote_literal('id') || ', b.id';
    else
      bucket_expr := bucket_expr || quote_literal('id') || ', null';
    end if;

    if has_name then
      bucket_expr := bucket_expr || ', ' || quote_literal('name') || ', b.name';
    end if;

    if has_public then
      bucket_expr := bucket_expr || ', ' || quote_literal('public') || ', b.public';
    end if;

    if has_type then
      bucket_expr := bucket_expr || ', ' || quote_literal('type') || ', b.type';
    end if;

    if has_file_size_limit then
      bucket_expr := bucket_expr || ', ' || quote_literal('file_size_limit') || ', b.file_size_limit';
    end if;

    if has_allowed_mime_types then
      bucket_expr := bucket_expr || ', ' || quote_literal('allowed_mime_types') || ', b.allowed_mime_types';
    end if;

    if has_owner_id then
      bucket_expr := bucket_expr || ', ' || quote_literal('owner_id') || ', b.owner_id';
    elsif has_owner then
      -- older storage versions used "owner"
      bucket_expr := bucket_expr || ', ' || quote_literal('owner_id') || ', b.owner';
    end if;

    if has_created_at then
      bucket_expr := bucket_expr || ', ' || quote_literal('created_at') || ', b.created_at';
    end if;

    if has_updated_at then
      bucket_expr := bucket_expr || ', ' || quote_literal('updated_at') || ', b.updated_at';
    end if;

    if has_objects then
      join_clause := $j$
        left join lateral (
          select
            count(*)::bigint as object_count,
            sum(
              case
                when (obj.metadata ? 'size')
                 and (obj.metadata->>'size') ~ '^[0-9]+$'
                then (obj.metadata->>'size')::bigint
                else null
              end
            ) as objects_bytes_est
          from storage.objects obj
          where obj.bucket_id = b.id
        ) o on true
      $j$;

      bucket_expr := bucket_expr
        || ', ' || quote_literal('object_count') || ', coalesce(o.object_count, 0)'
        || ', ' || quote_literal('objects_bytes_est') || ', o.objects_bytes_est';
    else
      join_clause := '';
      bucket_expr := bucket_expr
        || ', ' || quote_literal('object_count') || ', 0'
        || ', ' || quote_literal('objects_bytes_est') || ', null';
    end if;

    bucket_expr := bucket_expr || ')';

    sql := format(
      'select coalesce(jsonb_agg(%s order by %s), ''[]''::jsonb)
       from storage.buckets b
       %s',
      bucket_expr,
      order_expr,
      join_clause
    );

    execute sql into storage_buckets;
  end if;

  return base || jsonb_build_object('storage_buckets', storage_buckets);
end;
$_$;


ALTER FUNCTION "core"."comet_schema_report"("include_schemas" "text"[], "exclude_schemas" "text"[]) OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."enforce_site_gallery_max6"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO 'pg_catalog'
    AS $$
declare
  cnt int;
begin
  -- If the new row is soft-deleted, it doesn't count.
  if new.deleted_at is not null then
    return new;
  end if;

  -- Only re-check count when it matters:
  -- INSERT always matters
  -- UPDATE matters if: moved site_id OR became undeleted
  if tg_op = 'UPDATE' then
    if old.site_id = new.site_id and old.deleted_at is null and new.deleted_at is null then
      return new;
    end if;
  end if;

  select count(*)
    into cnt
  from core.site_images si
  where si.site_id = new.site_id
    and si.deleted_at is null
    and (tg_op <> 'UPDATE' or si.id <> new.id);

  if cnt >= 6 then
    raise exception 'Gallery limit reached: max 6 images per site';
  end if;

  return new;
end;
$$;


ALTER FUNCTION "core"."enforce_site_gallery_max6"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."prevent_staff_escalation"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO 'pg_catalog'
    AS $$
declare
  uid uuid := (select auth.uid());
  is_admin boolean;
begin
  -- service_role / non-jwt contexts often have null uid; allow those
  if uid is null then
    return new;
  end if;

  select exists (
    select 1
    from core.comet_staff cs
    where cs.auth_user_id = uid
      and cs.role = 'admin'
  ) into is_admin;

  if not is_admin then
    if new.role is distinct from old.role then
      raise exception 'Only admins can change staff role';
    end if;

    if new.auth_user_id is distinct from old.auth_user_id then
      raise exception 'Only admins can change auth_user_id';
    end if;
  end if;

  return new;
end;
$$;


ALTER FUNCTION "core"."prevent_staff_escalation"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "core"."set_default_theme_for_site"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    SET "search_path" TO 'pg_catalog'
    AS $$
begin
  if new.theme_id is null then
    select id
    into new.theme_id
    from core.themes
    order by is_default desc, created_at
    limit 1;

    if new.theme_id is null then
      raise exception 'Cannot create site: no themes exist in core.themes';
    end if;
  end if;

  return new;
end;
$$;


ALTER FUNCTION "core"."set_default_theme_for_site"() OWNER TO "postgres";


CREATE OR REPLACE FUNCTION "public"."set_updated_at"() RETURNS "trigger"
    LANGUAGE "plpgsql"
    AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$;


ALTER FUNCTION "public"."set_updated_at"() OWNER TO "postgres";

SET default_tablespace = '';

SET default_table_access_method = "heap";


CREATE TABLE IF NOT EXISTS "analytics"."lead_submissions" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "subdomain" "text",
    "site_id" "uuid",
    "professional_id" "uuid",
    "customer_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "outcome" "text" NOT NULL,
    "form_started_at_ms" bigint
);


ALTER TABLE "analytics"."lead_submissions" OWNER TO "postgres";


COMMENT ON TABLE "analytics"."lead_submissions" IS 'Customer lead submissions with form timing and outcomes';



COMMENT ON COLUMN "analytics"."lead_submissions"."outcome" IS 'Submission outcome: created, rate_limited, site_not_found, etc.';



CREATE TABLE IF NOT EXISTS "analytics"."link_clicks" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "link_block_id" "uuid" NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "utm_source" "text",
    "utm_medium" "text",
    "utm_campaign" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "analytics"."link_clicks" OWNER TO "postgres";


COMMENT ON TABLE "analytics"."link_clicks" IS 'Click tracking for link blocks';



CREATE TABLE IF NOT EXISTS "analytics"."site_visits" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "occurred_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "session_id" "uuid",
    "visitor_id" "uuid",
    "ip_hash" "text",
    "user_agent" "text",
    "referrer" "text",
    "utm_source" "text",
    "utm_medium" "text",
    "utm_campaign" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "country_code" "text",
    "device_type" "text"
);


ALTER TABLE "analytics"."site_visits" OWNER TO "postgres";


COMMENT ON TABLE "analytics"."site_visits" IS 'Page view analytics with device/country detection';



CREATE OR REPLACE VIEW "core"."all_site_data" AS
SELECT
    NULL::"uuid" AS "site_id",
    NULL::"text" AS "subdomain",
    NULL::boolean AS "is_published",
    NULL::"jsonb" AS "site_settings",
    NULL::timestamp with time zone AS "site_created_at",
    NULL::timestamp with time zone AS "site_updated_at",
    NULL::"uuid" AS "theme_id",
    NULL::"text" AS "theme_key",
    NULL::"text" AS "theme_name",
    NULL::"jsonb" AS "theme_config",
    NULL::"uuid" AS "professional_id",
    NULL::"text" AS "professional_handle",
    NULL::"text" AS "professional_display_name",
    NULL::"text" AS "professional_bio",
    NULL::"text" AS "professional_icon_bucket",
    NULL::"text" AS "professional_icon_path",
    NULL::"text" AS "professional_headshot_bucket",
    NULL::"text" AS "professional_headshot_path",
    NULL::"text" AS "professional_location_street_address",
    NULL::"text" AS "professional_location_city",
    NULL::"text" AS "professional_location_state",
    NULL::"text" AS "professional_location_postcode",
    NULL::"text" AS "professional_location_country",
    NULL::"jsonb" AS "blocks";


ALTER VIEW "core"."all_site_data" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."blocks" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "block_type" "text" DEFAULT 'link'::"text" NOT NULL,
    "title" "text",
    "url" "text",
    "icon_key" "text",
    "sort_order" integer DEFAULT 0 NOT NULL,
    "is_active" boolean DEFAULT true NOT NULL,
    "settings" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "block_group" "text" DEFAULT 'links'::"text" NOT NULL,
    "deleted_at" timestamp with time zone,
    CONSTRAINT "link_blocks_block_group_check" CHECK (("block_group" = ANY (ARRAY['links'::"text", 'sections'::"text"])))
);


ALTER TABLE "core"."blocks" OWNER TO "postgres";


COMMENT ON TABLE "core"."blocks" IS 'Polymorphic content blocks (links, sections) for sites';



COMMENT ON COLUMN "core"."blocks"."block_type" IS 'Block type:  link, services, gallery, bio, etc.';



COMMENT ON COLUMN "core"."blocks"."block_group" IS 'Group type:  links or sections';



CREATE TABLE IF NOT EXISTS "core"."comet_staff" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "auth_user_id" "uuid" NOT NULL,
    "role" "text" DEFAULT 'support'::"text" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "primary_email" "text",
    "name" "text",
    "phone" "text"
);

ALTER TABLE ONLY "core"."comet_staff" FORCE ROW LEVEL SECURITY;


ALTER TABLE "core"."comet_staff" OWNER TO "postgres";


COMMENT ON TABLE "core"."comet_staff" IS 'Internal staff with role-based access (support/admin)';



CREATE TABLE IF NOT EXISTS "core"."customers" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "email" "text",
    "phone" "text",
    "full_name" "text",
    "source" "text",
    "notes" "text",
    "external_id" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone
);


ALTER TABLE "core"."customers" OWNER TO "postgres";


COMMENT ON TABLE "core"."customers" IS 'Customer contacts managed by professionals (soft deletes enabled)';



COMMENT ON COLUMN "core"."customers"."deleted_at" IS 'Soft delete timestamp (NULL = active)';



CREATE TABLE IF NOT EXISTS "core"."email_subscriptions" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid",
    "list_key" character varying(50) DEFAULT 'marketing'::character varying NOT NULL,
    "email" "text" NOT NULL,
    "full_name" "text",
    "status" character varying(20) DEFAULT 'subscribed'::character varying NOT NULL,
    "subscribed_at" timestamp with time zone,
    "unsubscribed_at" timestamp with time zone,
    "unsubscribe_token" character varying(80) NOT NULL,
    "consent_source" character varying(50),
    "consent_ip_hash" "text",
    "consent_user_agent" "text",
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "email_lc" "text" NOT NULL,
    "qr_slug" "text"
);


ALTER TABLE "core"."email_subscriptions" OWNER TO "postgres";


COMMENT ON TABLE "core"."email_subscriptions" IS 'Email subscription lists (marketing, comet_updates)';



COMMENT ON COLUMN "core"."email_subscriptions"."list_key" IS 'List identifier:  marketing, comet_updates';



COMMENT ON COLUMN "core"."email_subscriptions"."status" IS 'Subscription status:  subscribed, unsubscribed, bounced, complained';



CREATE TABLE IF NOT EXISTS "core"."notification_receipts" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "notification_id" "uuid" NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "read_at" timestamp with time zone,
    "dismissed_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "core"."notification_receipts" OWNER TO "postgres";


COMMENT ON TABLE "core"."notification_receipts" IS 'Tracks read/dismiss status per professional per notification';



CREATE TABLE IF NOT EXISTS "core"."notifications" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid",
    "type" "text" NOT NULL,
    "title" "text" NOT NULL,
    "body" "text" NOT NULL,
    "cta_url" "text",
    "severity" "text" DEFAULT 'info'::"text" NOT NULL,
    "starts_at" timestamp with time zone,
    "ends_at" timestamp with time zone,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    CONSTRAINT "notifications_severity_check" CHECK (("severity" = ANY (ARRAY['info'::"text", 'warning'::"text", 'critical'::"text"])))
);


ALTER TABLE "core"."notifications" OWNER TO "postgres";


COMMENT ON TABLE "core"."notifications" IS 'In-app notifications (broadcast or targeted to professional)';



COMMENT ON COLUMN "core"."notifications"."severity" IS 'Notification severity: info, warning, critical';



CREATE TABLE IF NOT EXISTS "core"."professionals" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "auth_user_id" "uuid" NOT NULL,
    "handle" "text" NOT NULL,
    "display_name" "text" NOT NULL,
    "bio" "text",
    "country_code" "text",
    "timezone" "text",
    "status" "text" DEFAULT 'active'::"text" NOT NULL,
    "onboarding_step" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "phone" "text" NOT NULL,
    "primary_email" "text" NOT NULL,
    "first_name" "text" NOT NULL,
    "last_name" "text",
    "public_contact_number" "text",
    "public_contact_email" "text",
    "icon_bucket" "text" DEFAULT 'public-assets'::"text",
    "icon_path" "text",
    "headshot_bucket" "text" DEFAULT 'public-assets'::"text",
    "headshot_path" "text",
    "location_street_address" "text",
    "location_postcode" "text",
    "location_city" "text",
    "location_state" "text",
    "location_country" "text",
    "handle_lc" "text" NOT NULL,
    "qr_slug" "text" NOT NULL,
    "deleted_at" timestamp with time zone,
    CONSTRAINT "professionals_headshot_bucket_when_path" CHECK ((("headshot_path" IS NULL) OR ("headshot_bucket" IS NOT NULL))),
    CONSTRAINT "professionals_icon_bucket_when_path" CHECK ((("icon_path" IS NULL) OR ("icon_bucket" IS NOT NULL)))
);


ALTER TABLE "core"."professionals" OWNER TO "postgres";


COMMENT ON TABLE "core"."professionals" IS 'Professional user profiles with unique handles and QR codes';



COMMENT ON COLUMN "core"."professionals"."handle_lc" IS 'Lowercase version of handle for case-insensitive uniqueness';



COMMENT ON COLUMN "core"."professionals"."qr_slug" IS 'Unique slug for QR code generation (format: handle-random6)';



CREATE TABLE IF NOT EXISTS "core"."site_images" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "site_id" "uuid" NOT NULL,
    "bucket" "text" DEFAULT 'public-assets'::"text" NOT NULL,
    "path" "text" NOT NULL,
    "alt_text" "text",
    "sort_order" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    "is_active" boolean DEFAULT true NOT NULL
);


ALTER TABLE "core"."site_images" OWNER TO "postgres";


COMMENT ON TABLE "core"."site_images" IS 'Gallery images for sites (max 6 per site, enforced by trigger)';



CREATE TABLE IF NOT EXISTS "core"."sites" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "subdomain" "text" NOT NULL,
    "theme_id" "uuid",
    "is_published" boolean DEFAULT false NOT NULL,
    "settings" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "subdomain_changed_at" timestamp with time zone,
    "banner_bucket" "text" DEFAULT 'public-assets'::"text",
    "banner_path" "text",
    CONSTRAINT "sites_banner_bucket_when_path" CHECK ((("banner_path" IS NULL) OR ("banner_bucket" IS NOT NULL)))
);


ALTER TABLE "core"."sites" OWNER TO "postgres";


COMMENT ON TABLE "core"."sites" IS 'Professional websites with subdomains (1:1 with professionals)';



COMMENT ON COLUMN "core"."sites"."is_published" IS 'Whether site is publicly visible';



CREATE TABLE IF NOT EXISTS "core"."themes" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "key" "text" NOT NULL,
    "name" "text" NOT NULL,
    "description" "text",
    "config" "jsonb" DEFAULT '{}'::"jsonb" NOT NULL,
    "is_default" boolean DEFAULT false NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "core"."themes" OWNER TO "postgres";


COMMENT ON TABLE "core"."themes" IS 'Site themes with configuration (only 1 can be default)';



CREATE OR REPLACE VIEW "core"."public_site_payload" WITH ("security_invoker"='on') AS
 SELECT "s"."id" AS "site_id",
    "s"."professional_id",
    "s"."subdomain",
    "jsonb_build_object"('site', "jsonb_build_object"('id', "s"."id", 'subdomain', "s"."subdomain", 'settings', "s"."settings", 'is_published', "s"."is_published", 'banner', "jsonb_build_object"('bucket', "s"."banner_bucket", 'path', "s"."banner_path"), 'gallery', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "si"."id", 'bucket', "si"."bucket", 'path', "si"."path", 'alt_text', "si"."alt_text", 'sort_order', "si"."sort_order") ORDER BY "si"."sort_order", "si"."created_at") AS "jsonb_agg"
           FROM "core"."site_images" "si"
          WHERE (("si"."site_id" = "s"."id") AND ("si"."deleted_at" IS NULL) AND ("si"."is_active" = true))), '[]'::"jsonb")), 'professional', "jsonb_build_object"('id', "p"."id", 'handle', "p"."handle", 'display_name', "p"."display_name", 'bio', "p"."bio", 'country_code', "p"."country_code", 'timezone', "p"."timezone", 'public_contact_number', "p"."public_contact_number", 'public_contact_email', "p"."public_contact_email", 'icon', "jsonb_build_object"('bucket', "p"."icon_bucket", 'path', "p"."icon_path"), 'headshot', "jsonb_build_object"('bucket', "p"."headshot_bucket", 'path', "p"."headshot_path")), 'theme',
        CASE
            WHEN ("t"."id" IS NULL) THEN NULL::"jsonb"
            ELSE "jsonb_build_object"('id', "t"."id", 'key', "t"."key", 'name', "t"."name", 'config', "t"."config")
        END, 'links', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "b"."id", 'block_type', "b"."block_type", 'title', "b"."title", 'url', "b"."url", 'icon_key', "b"."icon_key", 'sort_order', "b"."sort_order", 'settings', "b"."settings") ORDER BY "b"."sort_order", "b"."created_at") AS "jsonb_agg"
           FROM "core"."blocks" "b"
          WHERE (("b"."site_id" = "s"."id") AND ("b"."block_group" = 'links'::"text") AND ("b"."is_active" = true) AND ("b"."deleted_at" IS NULL))), '[]'::"jsonb"), 'sections', COALESCE(( SELECT "jsonb_agg"("jsonb_build_object"('id', "b"."id", 'block_type', "b"."block_type", 'title', "b"."title", 'url', "b"."url", 'icon_key', "b"."icon_key", 'sort_order', "b"."sort_order", 'settings', "b"."settings") ORDER BY "b"."sort_order", "b"."created_at") AS "jsonb_agg"
           FROM "core"."blocks" "b"
          WHERE (("b"."site_id" = "s"."id") AND ("b"."block_group" = 'sections'::"text") AND ("b"."is_active" = true) AND ("b"."deleted_at" IS NULL))), '[]'::"jsonb")) AS "payload"
   FROM (("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
     LEFT JOIN "core"."themes" "t" ON (("t"."id" = "s"."theme_id")))
  WHERE ("s"."is_published" = true);


ALTER VIEW "core"."public_site_payload" OWNER TO "postgres";


CREATE TABLE IF NOT EXISTS "core"."services" (
    "id" "uuid" DEFAULT "gen_random_uuid"() NOT NULL,
    "professional_id" "uuid" NOT NULL,
    "title" "text" NOT NULL,
    "description" "text",
    "category" "text",
    "price_cents" integer NOT NULL,
    "currency_code" character(3) DEFAULT 'AUD'::"bpchar" NOT NULL,
    "duration_minutes" integer,
    "is_active" boolean DEFAULT true NOT NULL,
    "sort_order" integer DEFAULT 0 NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "deleted_at" timestamp with time zone,
    CONSTRAINT "services_duration_minutes_check" CHECK ((("duration_minutes" IS NULL) OR ("duration_minutes" > 0))),
    CONSTRAINT "services_price_cents_check" CHECK (("price_cents" >= 0))
);


ALTER TABLE "core"."services" OWNER TO "postgres";


COMMENT ON TABLE "core"."services" IS 'Services offered by professionals with pricing and duration';



COMMENT ON COLUMN "core"."services"."deleted_at" IS 'Soft delete timestamp (NULL = active)';



CREATE TABLE IF NOT EXISTS "core"."site_subdomain_aliases" (
    "id" "uuid" NOT NULL,
    "site_id" "uuid" NOT NULL,
    "subdomain" character varying(63) NOT NULL,
    "created_at" timestamp with time zone DEFAULT "now"() NOT NULL,
    "updated_at" timestamp with time zone DEFAULT "now"() NOT NULL
);


ALTER TABLE "core"."site_subdomain_aliases" OWNER TO "postgres";


COMMENT ON TABLE "core"."site_subdomain_aliases" IS 'Alternative subdomains that redirect to sites';


CREATE TABLE IF NOT EXISTS "public"."failed_jobs" (
    "id" bigint NOT NULL,
    "uuid" character varying(255) NOT NULL,
    "connection" "text" NOT NULL,
    "queue" "text" NOT NULL,
    "payload" "text" NOT NULL,
    "exception" "text" NOT NULL,
    "failed_at" timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE "public"."failed_jobs" OWNER TO "postgres";


CREATE SEQUENCE IF NOT EXISTS "public"."failed_jobs_id_seq"
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNER TO "postgres";


ALTER SEQUENCE "public"."failed_jobs_id_seq" OWNED BY "public"."failed_jobs"."id";



CREATE TABLE IF NOT EXISTS "public"."job_batches" (
    "id" character varying(255) NOT NULL,
    "name" character varying(255) NOT NULL,
    "total_jobs" integer NOT NULL,
    "pending_jobs" integer NOT NULL,
    "failed_jobs" integer NOT NULL,
    "failed_job_ids" "text" NOT NULL,
    "options" "text",
    "cancelled_at" integer,
    "created_at" integer NOT NULL,
    "finished_at" integer
);


ALTER TABLE "public"."job_batches" OWNER TO "postgres";



ALTER TABLE ONLY "public"."failed_jobs" ALTER COLUMN "id" SET DEFAULT "nextval"('"public"."failed_jobs_id_seq"'::"regclass");



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."comet_staff"
    ADD CONSTRAINT "comet_staff_Primary Email_key" UNIQUE ("primary_email");



ALTER TABLE ONLY "core"."comet_staff"
    ADD CONSTRAINT "comet_staff_auth_user_id_key" UNIQUE ("auth_user_id");



ALTER TABLE ONLY "core"."comet_staff"
    ADD CONSTRAINT "comet_staff_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."customers"
    ADD CONSTRAINT "customers_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."email_subscriptions"
    ADD CONSTRAINT "email_subscriptions_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."email_subscriptions"
    ADD CONSTRAINT "email_subscriptions_qr_slug_key" UNIQUE ("qr_slug");



ALTER TABLE ONLY "core"."blocks"
    ADD CONSTRAINT "link_blocks_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."notification_receipts"
    ADD CONSTRAINT "notification_receipts_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."notifications"
    ADD CONSTRAINT "notifications_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."professionals"
    ADD CONSTRAINT "professionals_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."services"
    ADD CONSTRAINT "services_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."site_images"
    ADD CONSTRAINT "site_images_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."site_subdomain_aliases"
    ADD CONSTRAINT "site_subdomain_aliases_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."sites"
    ADD CONSTRAINT "sites_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "core"."themes"
    ADD CONSTRAINT "themes_key_key" UNIQUE ("key");



ALTER TABLE ONLY "core"."themes"
    ADD CONSTRAINT "themes_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_pkey" PRIMARY KEY ("id");



ALTER TABLE ONLY "public"."failed_jobs"
    ADD CONSTRAINT "failed_jobs_uuid_unique" UNIQUE ("uuid");



ALTER TABLE ONLY "public"."job_batches"
    ADD CONSTRAINT "job_batches_pkey" PRIMARY KEY ("id");



CREATE INDEX "analytics_link_clicks_professional_occurred_idx" ON "analytics"."link_clicks" USING "btree" ("professional_id", "occurred_at");



CREATE INDEX "analytics_site_visits_professional_occurred_idx" ON "analytics"."site_visits" USING "btree" ("professional_id", "occurred_at");



CREATE INDEX "lead_submissions_ip_time_idx" ON "analytics"."lead_submissions" USING "btree" ("ip_hash", "occurred_at" DESC);



CREATE INDEX "lead_submissions_prof_time_idx" ON "analytics"."lead_submissions" USING "btree" ("professional_id", "occurred_at" DESC);



CREATE INDEX "lead_submissions_site_time_idx" ON "analytics"."lead_submissions" USING "btree" ("site_id", "occurred_at" DESC);



CREATE INDEX "link_clicks_link_time_idx" ON "analytics"."link_clicks" USING "btree" ("link_block_id", "occurred_at");



CREATE INDEX "link_clicks_pro_date_range_idx" ON "analytics"."link_clicks" USING "btree" ("professional_id", "occurred_at" DESC) INCLUDE ("link_block_id");



CREATE INDEX "link_clicks_professional_time_idx" ON "analytics"."link_clicks" USING "btree" ("professional_id", "occurred_at");



CREATE INDEX "link_clicks_site_time_idx" ON "analytics"."link_clicks" USING "btree" ("site_id", "occurred_at");



CREATE INDEX "site_visits_pro_date_range_idx" ON "analytics"."site_visits" USING "btree" ("professional_id", "occurred_at" DESC) INCLUDE ("country_code", "device_type");



CREATE INDEX "site_visits_professional_time_idx" ON "analytics"."site_visits" USING "btree" ("professional_id", "occurred_at");



CREATE INDEX "site_visits_site_time_idx" ON "analytics"."site_visits" USING "btree" ("site_id", "occurred_at");



CREATE UNIQUE INDEX "blocks_links_site_group_sort_uq" ON "core"."blocks" USING "btree" ("site_id", "block_group", "sort_order") WHERE ("block_group" = 'links'::"text");



CREATE UNIQUE INDEX "blocks_sections_site_group_sort_uq" ON "core"."blocks" USING "btree" ("site_id", "block_group", "sort_order") WHERE ("block_group" = 'sections'::"text");



CREATE UNIQUE INDEX "blocks_sections_site_group_type_uq" ON "core"."blocks" USING "btree" ("site_id", "block_group", "block_type") WHERE ("block_group" = 'sections'::"text");



CREATE INDEX "blocks_site_group_active_idx" ON "core"."blocks" USING "btree" ("site_id", "block_group", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "core_link_blocks_professional_sort_idx" ON "core"."blocks" USING "btree" ("professional_id", "sort_order");



CREATE UNIQUE INDEX "core_professionals_handle_lc_unique" ON "core"."professionals" USING "btree" ("handle_lc") WHERE ("deleted_at" IS NULL);



CREATE INDEX "core_site_subdomain_aliases_site_id_idx" ON "core"."site_subdomain_aliases" USING "btree" ("site_id");



CREATE UNIQUE INDEX "core_site_subdomain_aliases_subdomain_lower_unique" ON "core"."site_subdomain_aliases" USING "btree" ("lower"(("subdomain")::"text"));



CREATE UNIQUE INDEX "core_sites_subdomain_lower_unique" ON "core"."sites" USING "btree" ("lower"("subdomain"));



CREATE INDEX "customers_professional_deleted_at_idx" ON "core"."customers" USING "btree" ("professional_id", "deleted_at");



CREATE INDEX "customers_professional_email_search_idx" ON "core"."customers" USING "btree" ("professional_id", "lower"("email")) WHERE (("email" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE UNIQUE INDEX "customers_professional_email_unique" ON "core"."customers" USING "btree" ("professional_id", "lower"("email")) WHERE ("email" IS NOT NULL);



CREATE INDEX "customers_professional_id_idx" ON "core"."customers" USING "btree" ("professional_id");



CREATE INDEX "customers_professional_name_search_idx" ON "core"."customers" USING "btree" ("professional_id", "lower"("full_name")) WHERE (("full_name" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE INDEX "customers_professional_phone_search_idx" ON "core"."customers" USING "btree" ("professional_id", "phone") WHERE (("phone" IS NOT NULL) AND ("deleted_at" IS NULL));



CREATE UNIQUE INDEX "customers_professional_phone_unique" ON "core"."customers" USING "btree" ("professional_id", "phone") WHERE ("phone" IS NOT NULL);



CREATE INDEX "email_subs_global_list_status_idx" ON "core"."email_subscriptions" USING "btree" ("list_key", "status") WHERE ("professional_id" IS NULL);



CREATE INDEX "email_subs_pro_list_status_idx" ON "core"."email_subscriptions" USING "btree" ("professional_id", "list_key", "status") WHERE ("professional_id" IS NOT NULL);



CREATE INDEX "email_subscriptions_lookup_idx" ON "core"."email_subscriptions" USING "btree" ("professional_id", "list_key", "status");



CREATE UNIQUE INDEX "email_subscriptions_unique_global_list_email_lc" ON "core"."email_subscriptions" USING "btree" ("list_key", "email_lc") WHERE ("professional_id" IS NULL);



CREATE UNIQUE INDEX "email_subscriptions_unique_pro_list_email_lc" ON "core"."email_subscriptions" USING "btree" ("professional_id", "list_key", "email_lc") WHERE ("professional_id" IS NOT NULL);



CREATE UNIQUE INDEX "email_subscriptions_unsubscribe_token_unique" ON "core"."email_subscriptions" USING "btree" ("unsubscribe_token");



CREATE INDEX "link_blocks_pro_group_sort_idx" ON "core"."blocks" USING "btree" ("professional_id", "block_group", "sort_order");



CREATE INDEX "link_blocks_professional_id_idx" ON "core"."blocks" USING "btree" ("professional_id");



CREATE INDEX "link_blocks_site_group_sort_idx" ON "core"."blocks" USING "btree" ("site_id", "block_group", "sort_order");



CREATE INDEX "link_blocks_site_id_idx" ON "core"."blocks" USING "btree" ("site_id", "sort_order");



CREATE UNIQUE INDEX "notification_receipts_notification_professional_uq" ON "core"."notification_receipts" USING "btree" ("notification_id", "professional_id");



CREATE INDEX "notifications_broadcast_active_idx" ON "core"."notifications" USING "btree" ("created_at" DESC) WHERE ("professional_id" IS NULL);



CREATE INDEX "notifications_broadcast_idx" ON "core"."notifications" USING "btree" ("created_at" DESC) WHERE ("professional_id" IS NULL);



CREATE INDEX "notifications_pro_active_idx" ON "core"."notifications" USING "btree" ("professional_id", "created_at" DESC) WHERE ("professional_id" IS NOT NULL);



CREATE INDEX "notifications_target_idx" ON "core"."notifications" USING "btree" ("professional_id", "created_at" DESC);



CREATE UNIQUE INDEX "professionals_auth_user_id_unique" ON "core"."professionals" USING "btree" ("auth_user_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "professionals_deleted_at_idx" ON "core"."professionals" USING "btree" ("deleted_at") WHERE ("deleted_at" IS NULL);



CREATE INDEX "professionals_email_search_idx" ON "core"."professionals" USING "btree" ("lower"("primary_email"));



CREATE UNIQUE INDEX "professionals_email_unique" ON "core"."professionals" USING "btree" ("primary_email") WHERE ("deleted_at" IS NULL);



CREATE UNIQUE INDEX "professionals_public_contact_email_unique" ON "core"."professionals" USING "btree" ("public_contact_email") WHERE ("public_contact_email" IS NOT NULL);



CREATE UNIQUE INDEX "professionals_public_contact_number_unique" ON "core"."professionals" USING "btree" ("public_contact_number") WHERE ("public_contact_number" IS NOT NULL);



CREATE UNIQUE INDEX "professionals_qr_slug_unique" ON "core"."professionals" USING "btree" ("qr_slug") WHERE (("deleted_at" IS NULL) AND ("qr_slug" IS NOT NULL));



CREATE INDEX "receipts_pro_idx" ON "core"."notification_receipts" USING "btree" ("professional_id", "updated_at" DESC);



CREATE INDEX "receipts_unread_idx" ON "core"."notification_receipts" USING "btree" ("professional_id", "notification_id") WHERE (("read_at" IS NULL) AND ("dismissed_at" IS NULL));



CREATE INDEX "services_active_order_idx" ON "core"."services" USING "btree" ("professional_id", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "services_pro_active_sort_covering_idx" ON "core"."services" USING "btree" ("professional_id", "sort_order") INCLUDE ("title", "price_cents", "is_active") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE INDEX "services_prof_active_idx" ON "core"."services" USING "btree" ("professional_id", "is_active");



CREATE INDEX "services_prof_sort_idx" ON "core"."services" USING "btree" ("professional_id", "sort_order", "created_at");



CREATE INDEX "services_professional_id_deleted_at_idx" ON "core"."services" USING "btree" ("professional_id", "deleted_at");



CREATE UNIQUE INDEX "services_professional_sort_order_uq" ON "core"."services" USING "btree" ("professional_id", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "site_images_site_active_idx" ON "core"."site_images" USING "btree" ("site_id") WHERE ("deleted_at" IS NULL);



CREATE INDEX "site_images_site_active_sort_idx" ON "core"."site_images" USING "btree" ("site_id", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE UNIQUE INDEX "site_images_site_sort_active_unique" ON "core"."site_images" USING "btree" ("site_id", "sort_order") WHERE ("deleted_at" IS NULL);



CREATE INDEX "site_images_site_sort_idx" ON "core"."site_images" USING "btree" ("site_id", "sort_order");



CREATE UNIQUE INDEX "site_images_site_sort_order_active_uq" ON "core"."site_images" USING "btree" ("site_id", "sort_order") WHERE (("deleted_at" IS NULL) AND ("is_active" = true));



CREATE UNIQUE INDEX "sites_professional_unique" ON "core"."sites" USING "btree" ("professional_id");



CREATE UNIQUE INDEX "themes_single_default" ON "core"."themes" USING "btree" ("is_default") WHERE ("is_default" = true);



CREATE OR REPLACE VIEW "core"."all_site_data" AS
 SELECT "s"."id" AS "site_id",
    "s"."subdomain",
    "s"."is_published",
    "s"."settings" AS "site_settings",
    "s"."created_at" AS "site_created_at",
    "s"."updated_at" AS "site_updated_at",
    "t"."id" AS "theme_id",
    "t"."key" AS "theme_key",
    "t"."name" AS "theme_name",
    "t"."config" AS "theme_config",
    "p"."id" AS "professional_id",
    "p"."handle" AS "professional_handle",
    "p"."display_name" AS "professional_display_name",
    "p"."bio" AS "professional_bio",
    "p"."icon_bucket" AS "professional_icon_bucket",
    "p"."icon_path" AS "professional_icon_path",
    "p"."headshot_bucket" AS "professional_headshot_bucket",
    "p"."headshot_path" AS "professional_headshot_path",
    "p"."location_street_address" AS "professional_location_street_address",
    "p"."location_city" AS "professional_location_city",
    "p"."location_state" AS "professional_location_state",
    "p"."location_postcode" AS "professional_location_postcode",
    "p"."location_country" AS "professional_location_country",
    COALESCE("jsonb_agg"("jsonb_build_object"('id', "b"."id", 'site_id', "b"."site_id", 'professional_id', "b"."professional_id", 'block_type', "b"."block_type", 'block_group', "b"."block_group", 'title', "b"."title", 'url', "b"."url", 'icon_key', "b"."icon_key", 'sort_order', "b"."sort_order", 'is_active', "b"."is_active", 'settings', "b"."settings", 'created_at', "b"."created_at", 'updated_at', "b"."updated_at") ORDER BY "b"."sort_order") FILTER (WHERE ("b"."id" IS NOT NULL)), '[]'::"jsonb") AS "blocks"
   FROM ((("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
     LEFT JOIN "core"."themes" "t" ON (("t"."id" = "s"."theme_id")))
     LEFT JOIN "core"."blocks" "b" ON (("b"."site_id" = "s"."id")))
  GROUP BY "s"."id", "t"."id", "p"."id";



CREATE OR REPLACE TRIGGER "enforce_site_gallery_max6" BEFORE INSERT OR UPDATE OF "site_id", "deleted_at" ON "core"."site_images" FOR EACH ROW EXECUTE FUNCTION "core"."enforce_site_gallery_max6"();



CREATE OR REPLACE TRIGGER "prevent_staff_escalation" BEFORE UPDATE ON "core"."comet_staff" FOR EACH ROW EXECUTE FUNCTION "core"."prevent_staff_escalation"();



CREATE OR REPLACE TRIGGER "set_default_theme_on_sites" BEFORE INSERT ON "core"."sites" FOR EACH ROW EXECUTE FUNCTION "core"."set_default_theme_for_site"();



CREATE OR REPLACE TRIGGER "set_timestamp_comet_staff" BEFORE UPDATE ON "core"."comet_staff" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_customers" BEFORE UPDATE ON "core"."customers" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_link_blocks" BEFORE UPDATE ON "core"."blocks" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_professionals" BEFORE UPDATE ON "core"."professionals" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_site_images" BEFORE UPDATE ON "core"."site_images" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_site_subdomain_aliases" BEFORE UPDATE ON "core"."site_subdomain_aliases" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_sites" BEFORE UPDATE ON "core"."sites" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



CREATE OR REPLACE TRIGGER "set_timestamp_themes" BEFORE UPDATE ON "core"."themes" FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_customer_fk" FOREIGN KEY ("customer_id") REFERENCES "core"."customers"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_customer_id_fkey" FOREIGN KEY ("customer_id") REFERENCES "core"."customers"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_site_fk" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."lead_submissions"
    ADD CONSTRAINT "lead_submissions_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_block_fk" FOREIGN KEY ("link_block_id") REFERENCES "core"."blocks"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_link_block_id_fkey" FOREIGN KEY ("link_block_id") REFERENCES "core"."blocks"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_site_fk" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."link_clicks"
    ADD CONSTRAINT "link_clicks_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_site_fk" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "analytics"."site_visits"
    ADD CONSTRAINT "site_visits_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."blocks"
    ADD CONSTRAINT "blocks_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."blocks"
    ADD CONSTRAINT "blocks_site_fk" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."comet_staff"
    ADD CONSTRAINT "comet_staff_auth_user_id_fkey" FOREIGN KEY ("auth_user_id") REFERENCES "auth"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."customers"
    ADD CONSTRAINT "customers_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."customers"
    ADD CONSTRAINT "customers_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."email_subscriptions"
    ADD CONSTRAINT "email_subscriptions_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."email_subscriptions"
    ADD CONSTRAINT "email_subscriptions_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."blocks"
    ADD CONSTRAINT "link_blocks_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."blocks"
    ADD CONSTRAINT "link_blocks_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."notification_receipts"
    ADD CONSTRAINT "notification_receipts_notification_id_fkey" FOREIGN KEY ("notification_id") REFERENCES "core"."notifications"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."notification_receipts"
    ADD CONSTRAINT "notification_receipts_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."notifications"
    ADD CONSTRAINT "notifications_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."notifications"
    ADD CONSTRAINT "notifications_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."professionals"
    ADD CONSTRAINT "professionals_auth_user_id_fkey" FOREIGN KEY ("auth_user_id") REFERENCES "auth"."users"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."notification_receipts"
    ADD CONSTRAINT "receipts_notification_fk" FOREIGN KEY ("notification_id") REFERENCES "core"."notifications"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."notification_receipts"
    ADD CONSTRAINT "receipts_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."services"
    ADD CONSTRAINT "services_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."services"
    ADD CONSTRAINT "services_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."site_images"
    ADD CONSTRAINT "site_images_site_fk" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."site_images"
    ADD CONSTRAINT "site_images_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."site_subdomain_aliases"
    ADD CONSTRAINT "site_subdomain_aliases_site_fk" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."site_subdomain_aliases"
    ADD CONSTRAINT "site_subdomain_aliases_site_id_fkey" FOREIGN KEY ("site_id") REFERENCES "core"."sites"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."sites"
    ADD CONSTRAINT "sites_professional_fk" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."sites"
    ADD CONSTRAINT "sites_professional_id_fkey" FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;



ALTER TABLE ONLY "core"."sites"
    ADD CONSTRAINT "sites_theme_fk" FOREIGN KEY ("theme_id") REFERENCES "core"."themes"("id") ON DELETE SET NULL;



ALTER TABLE ONLY "core"."sites"
    ADD CONSTRAINT "sites_theme_id_fkey" FOREIGN KEY ("theme_id") REFERENCES "core"."themes"("id");



ALTER TABLE "analytics"."lead_submissions" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "analytics"."link_clicks" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "link_clicks_anyone_insert_valid_block" ON "analytics"."link_clicks" FOR INSERT TO "anon" WITH CHECK ((EXISTS ( SELECT 1
   FROM ("core"."blocks" "b"
     JOIN "core"."sites" "s" ON (("s"."id" = "b"."site_id")))
  WHERE (("b"."id" = "link_clicks"."link_block_id") AND ("b"."site_id" = "link_clicks"."site_id") AND ("b"."professional_id" = "link_clicks"."professional_id") AND ("b"."is_active" = true) AND ("s"."is_published" = true)))));



CREATE POLICY "link_clicks_staff_all" ON "analytics"."link_clicks" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT ( SELECT "auth"."uid"() AS "uid") AS "uid"))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT ( SELECT "auth"."uid"() AS "uid") AS "uid")))));



ALTER TABLE "analytics"."site_visits" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_visits_anyone_insert_valid_site" ON "analytics"."site_visits" FOR INSERT TO "anon" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."sites" "s"
  WHERE (("s"."id" = "site_visits"."site_id") AND ("s"."professional_id" = "site_visits"."professional_id") AND ("s"."is_published" = true)))));



CREATE POLICY "site_visits_staff_all" ON "analytics"."site_visits" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT ( SELECT "auth"."uid"() AS "uid") AS "uid"))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT ( SELECT "auth"."uid"() AS "uid") AS "uid")))));



CREATE POLICY "aliases_pro_all" ON "core"."site_subdomain_aliases" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM ("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
  WHERE (("s"."id" = "site_subdomain_aliases"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM ("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
  WHERE (("s"."id" = "site_subdomain_aliases"."site_id") AND ("p"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "aliases_public_read" ON "core"."site_subdomain_aliases" FOR SELECT TO "anon" USING ((EXISTS ( SELECT 1
   FROM "core"."sites" "s"
  WHERE (("s"."id" = "site_subdomain_aliases"."site_id") AND ("s"."is_published" = true)))));



CREATE POLICY "aliases_staff_all" ON "core"."site_subdomain_aliases" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff"
  WHERE ("comet_staff"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff"
  WHERE ("comet_staff"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "core"."blocks" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "core"."comet_staff" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "comet_staff_delete_admin" ON "core"."comet_staff" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE (("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) AND ("cs"."role" = 'admin'::"text")))));



CREATE POLICY "comet_staff_insert_admin" ON "core"."comet_staff" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE (("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) AND ("cs"."role" = 'admin'::"text")))));



CREATE POLICY "comet_staff_select_authenticated" ON "core"."comet_staff" FOR SELECT TO "authenticated" USING ((("auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE (("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) AND ("cs"."role" = 'admin'::"text"))))));



CREATE POLICY "comet_staff_update_authenticated" ON "core"."comet_staff" FOR UPDATE TO "authenticated" USING ((("auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE (("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) AND ("cs"."role" = 'admin'::"text")))))) WITH CHECK ((("auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE (("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) AND ("cs"."role" = 'admin'::"text"))))));



ALTER TABLE "core"."customers" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "customers_all_authenticated" ON "core"."customers" TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "customers"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "customers"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "email_subs_pro_all" ON "core"."email_subscriptions" TO "authenticated" USING (("professional_id" = ( SELECT "professionals"."id"
   FROM "core"."professionals"
  WHERE (("professionals"."auth_user_id" = "auth"."uid"()) AND ("professionals"."deleted_at" IS NULL))))) WITH CHECK (("professional_id" = ( SELECT "professionals"."id"
   FROM "core"."professionals"
  WHERE (("professionals"."auth_user_id" = "auth"."uid"()) AND ("professionals"."deleted_at" IS NULL)))));



CREATE POLICY "email_subs_public_insert" ON "core"."email_subscriptions" FOR INSERT TO "anon" WITH CHECK (true);



CREATE POLICY "email_subs_public_unsubscribe" ON "core"."email_subscriptions" FOR SELECT TO "anon" USING (("unsubscribe_token" IS NOT NULL));



CREATE POLICY "email_subs_staff_all" ON "core"."email_subscriptions" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff"
  WHERE ("comet_staff"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff"
  WHERE ("comet_staff"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "core"."email_subscriptions" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "link_blocks_delete_authenticated" ON "core"."blocks" FOR DELETE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "blocks"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "link_blocks_insert_authenticated" ON "core"."blocks" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "blocks"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "link_blocks_public_read_active_published" ON "core"."blocks" FOR SELECT TO "anon" USING ((("is_active" = true) AND (EXISTS ( SELECT 1
   FROM "core"."sites" "s"
  WHERE (("s"."id" = "blocks"."site_id") AND ("s"."is_published" = true))))));



CREATE POLICY "link_blocks_select_authenticated" ON "core"."blocks" FOR SELECT TO "authenticated" USING (((("is_active" = true) AND (EXISTS ( SELECT 1
   FROM "core"."sites" "s"
  WHERE (("s"."id" = "blocks"."site_id") AND ("s"."is_published" = true))))) OR (EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "blocks"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "link_blocks_update_authenticated" ON "core"."blocks" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "blocks"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "blocks"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



ALTER TABLE "core"."notification_receipts" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "core"."notifications" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "core"."professionals" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "professionals_all_authenticated" ON "core"."professionals" TO "authenticated" USING ((("auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")))))) WITH CHECK ((("auth_user_id" = ( SELECT "auth"."uid"() AS "uid")) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid"))))));



ALTER TABLE "core"."services" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "services_pro_all" ON "core"."services" TO "authenticated" USING (("professional_id" = ( SELECT "professionals"."id"
   FROM "core"."professionals"
  WHERE (("professionals"."auth_user_id" = "auth"."uid"()) AND ("professionals"."deleted_at" IS NULL))))) WITH CHECK (("professional_id" = ( SELECT "professionals"."id"
   FROM "core"."professionals"
  WHERE (("professionals"."auth_user_id" = "auth"."uid"()) AND ("professionals"."deleted_at" IS NULL)))));



CREATE POLICY "services_staff_all" ON "core"."services" TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff"
  WHERE ("comet_staff"."auth_user_id" = "auth"."uid"())))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff"
  WHERE ("comet_staff"."auth_user_id" = "auth"."uid"()))));



ALTER TABLE "core"."site_images" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "site_images_delete_staff" ON "core"."site_images" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")))));



CREATE POLICY "site_images_insert_authenticated" ON "core"."site_images" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM ("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
  WHERE (("s"."id" = "site_images"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "site_images_public_read_published" ON "core"."site_images" FOR SELECT TO "anon" USING ((("deleted_at" IS NULL) AND (EXISTS ( SELECT 1
   FROM "core"."sites" "s"
  WHERE (("s"."id" = "site_images"."site_id") AND ("s"."is_published" = true))))));



CREATE POLICY "site_images_select_authenticated" ON "core"."site_images" FOR SELECT TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM ("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
  WHERE (("s"."id" = "site_images"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "site_images_update_authenticated" ON "core"."site_images" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM ("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
  WHERE (("s"."id" = "site_images"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM ("core"."sites" "s"
     JOIN "core"."professionals" "p" ON (("p"."id" = "s"."professional_id")))
  WHERE (("s"."id" = "site_images"."site_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



ALTER TABLE "core"."site_subdomain_aliases" ENABLE ROW LEVEL SECURITY;


ALTER TABLE "core"."sites" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "sites_delete_authenticated" ON "core"."sites" FOR DELETE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "sites"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "sites_insert_authenticated" ON "core"."sites" FOR INSERT TO "authenticated" WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "sites"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "sites_public_read_published" ON "core"."sites" FOR SELECT TO "anon" USING (("is_published" = true));



CREATE POLICY "sites_select_authenticated" ON "core"."sites" FOR SELECT TO "authenticated" USING ((("is_published" = true) OR (EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "sites"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



CREATE POLICY "sites_update_authenticated" ON "core"."sites" FOR UPDATE TO "authenticated" USING (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "sites"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"()))))) WITH CHECK (((EXISTS ( SELECT 1
   FROM "core"."professionals" "p"
  WHERE (("p"."id" = "sites"."professional_id") AND ("p"."auth_user_id" = "auth"."uid"()) AND ("p"."deleted_at" IS NULL)))) OR (EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = "auth"."uid"())))));



ALTER TABLE "core"."themes" ENABLE ROW LEVEL SECURITY;


CREATE POLICY "themes_delete_staff" ON "core"."themes" FOR DELETE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")))));



CREATE POLICY "themes_insert_staff" ON "core"."themes" FOR INSERT TO "authenticated" WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")))));



CREATE POLICY "themes_public_read" ON "core"."themes" FOR SELECT TO "anon" USING (true);



CREATE POLICY "themes_select_authenticated" ON "core"."themes" FOR SELECT TO "authenticated" USING (true);



CREATE POLICY "themes_update_staff" ON "core"."themes" FOR UPDATE TO "authenticated" USING ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid"))))) WITH CHECK ((EXISTS ( SELECT 1
   FROM "core"."comet_staff" "cs"
  WHERE ("cs"."auth_user_id" = ( SELECT "auth"."uid"() AS "uid")))));





ALTER PUBLICATION "supabase_realtime" OWNER TO "postgres";


GRANT USAGE ON SCHEMA "analytics" TO "anon";
GRANT USAGE ON SCHEMA "analytics" TO "authenticated";
GRANT USAGE ON SCHEMA "analytics" TO "service_role";



GRANT USAGE ON SCHEMA "core" TO "anon";
GRANT USAGE ON SCHEMA "core" TO "authenticated";
GRANT USAGE ON SCHEMA "core" TO "service_role";



GRANT USAGE ON SCHEMA "public" TO "postgres";
GRANT USAGE ON SCHEMA "public" TO "anon";
GRANT USAGE ON SCHEMA "public" TO "authenticated";
GRANT USAGE ON SCHEMA "public" TO "service_role";

























































































































































GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "anon";
GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "authenticated";
GRANT ALL ON FUNCTION "public"."set_updated_at"() TO "service_role";












GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."lead_submissions" TO "authenticated";
GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."lead_submissions" TO "service_role";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."link_clicks" TO "service_role";
GRANT SELECT,INSERT ON TABLE "analytics"."link_clicks" TO "anon";



GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE "analytics"."site_visits" TO "service_role";
GRANT SELECT,INSERT ON TABLE "analytics"."site_visits" TO "anon";



GRANT ALL ON TABLE "core"."all_site_data" TO "authenticated";
GRANT ALL ON TABLE "core"."all_site_data" TO "service_role";



GRANT ALL ON TABLE "core"."blocks" TO "authenticated";
GRANT ALL ON TABLE "core"."blocks" TO "service_role";



GRANT ALL ON TABLE "core"."comet_staff" TO "service_role";
GRANT SELECT ON TABLE "core"."comet_staff" TO "authenticated";



GRANT UPDATE("primary_email") ON TABLE "core"."comet_staff" TO "authenticated";



GRANT UPDATE("name") ON TABLE "core"."comet_staff" TO "authenticated";



GRANT UPDATE("phone") ON TABLE "core"."comet_staff" TO "authenticated";



GRANT ALL ON TABLE "core"."customers" TO "authenticated";
GRANT ALL ON TABLE "core"."customers" TO "service_role";



GRANT ALL ON TABLE "core"."email_subscriptions" TO "authenticated";
GRANT ALL ON TABLE "core"."email_subscriptions" TO "service_role";



GRANT ALL ON TABLE "core"."notification_receipts" TO "authenticated";
GRANT ALL ON TABLE "core"."notification_receipts" TO "service_role";



GRANT ALL ON TABLE "core"."notifications" TO "authenticated";
GRANT ALL ON TABLE "core"."notifications" TO "service_role";



GRANT ALL ON TABLE "core"."professionals" TO "authenticated";
GRANT ALL ON TABLE "core"."professionals" TO "service_role";



GRANT ALL ON TABLE "core"."site_images" TO "authenticated";
GRANT ALL ON TABLE "core"."site_images" TO "service_role";



GRANT ALL ON TABLE "core"."sites" TO "authenticated";
GRANT ALL ON TABLE "core"."sites" TO "service_role";



GRANT ALL ON TABLE "core"."themes" TO "authenticated";
GRANT ALL ON TABLE "core"."themes" TO "service_role";



GRANT ALL ON TABLE "core"."public_site_payload" TO "authenticated";
GRANT ALL ON TABLE "core"."public_site_payload" TO "service_role";
GRANT SELECT ON TABLE "core"."public_site_payload" TO "anon";



GRANT ALL ON TABLE "core"."services" TO "authenticated";
GRANT ALL ON TABLE "core"."services" TO "service_role";



GRANT ALL ON TABLE "core"."site_subdomain_aliases" TO "authenticated";
GRANT ALL ON TABLE "core"."site_subdomain_aliases" TO "service_role";



GRANT ALL ON TABLE "public"."failed_jobs" TO "service_role";



GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "anon";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "authenticated";
GRANT ALL ON SEQUENCE "public"."failed_jobs_id_seq" TO "service_role";



GRANT ALL ON TABLE "public"."job_batches" TO "service_role";









ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "analytics" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "analytics" GRANT SELECT,INSERT,DELETE,UPDATE ON TABLES TO "service_role";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "core" GRANT ALL ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "core" GRANT ALL ON TABLES TO "service_role";



ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON SEQUENCES TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "anon";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON FUNCTIONS TO "service_role";






ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "postgres";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "authenticated";
ALTER DEFAULT PRIVILEGES FOR ROLE "postgres" IN SCHEMA "public" GRANT ALL ON TABLES TO "service_role";



-- === Begin 20260108081832_bug_fixes_for_locallity_change.sql ===
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
-- === End 20260108081832_bug_fixes_for_locallity_change.sql ===


-- === Begin 20260108091003_bug_fixes_again_for_change.sql ===
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
-- === End 20260108091003_bug_fixes_again_for_change.sql ===


-- === Begin 20260108091806_more_fixes_before.sql ===
-- 1) Soft-delete–aware unique indexes on core.blocks
DROP INDEX IF EXISTS core.blocks_links_site_group_sort_uq;
CREATE UNIQUE INDEX blocks_links_site_group_sort_uq
  ON core.blocks (site_id, block_group, sort_order)
  WHERE block_group = 'links' AND deleted_at IS NULL;

DROP INDEX IF EXISTS core.blocks_sections_site_group_sort_uq;
CREATE UNIQUE INDEX blocks_sections_site_group_sort_uq
  ON core.blocks (site_id, block_group, sort_order)
  WHERE block_group = 'sections' AND deleted_at IS NULL;

DROP INDEX IF EXISTS core.blocks_sections_site_group_type_uq;
CREATE UNIQUE INDEX blocks_sections_site_group_type_uq
  ON core.blocks (site_id, block_group, block_type)
  WHERE block_group = 'sections' AND deleted_at IS NULL;

-- 2) Defaulting triggers for email_subscriptions
--    (ensures email_lc and unsubscribe_token are populated)
DROP TRIGGER IF EXISTS trg_set_email_subscription_defaults_biur ON core.email_subscriptions;
CREATE TRIGGER trg_set_email_subscription_defaults_biur
BEFORE INSERT OR UPDATE ON core.email_subscriptions
FOR EACH ROW
EXECUTE FUNCTION core.set_email_subscription_defaults();

-- 3) Defaulting triggers for professionals
--    (ensures handle_lc and qr_slug are populated)
DROP TRIGGER IF EXISTS trg_set_professional_defaults_biur ON core.professionals;
CREATE TRIGGER trg_set_professional_defaults_biur
BEFORE INSERT OR UPDATE ON core.professionals
FOR EACH ROW
EXECUTE FUNCTION core.set_professional_defaults();
-- === End 20260108091806_more_fixes_before.sql ===


-- === Begin 20260108100309_further_bug_fixes.sql ===
-- 1) Drop redundant triggers (keep a single trigger per table that sets defaults)
DO $$
BEGIN
  -- core.email_subscriptions: keep set_email_subscription_defaults_insupd
  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'set_email_subscription_defaults_trg'
      AND tgrelid = 'core.email_subscriptions'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER set_email_subscription_defaults_trg ON core.email_subscriptions';
  END IF;

  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'trg_set_email_subscription_defaults_biur'
      AND tgrelid = 'core.email_subscriptions'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER trg_set_email_subscription_defaults_biur ON core.email_subscriptions';
  END IF;

  -- core.professionals: keep set_professional_defaults_insupd
  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'set_professional_defaults_trg'
      AND tgrelid = 'core.professionals'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER set_professional_defaults_trg ON core.professionals';
  END IF;

  IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgname = 'trg_set_professional_defaults_biur'
      AND tgrelid = 'core.professionals'::regclass
  ) THEN
    EXECUTE 'DROP TRIGGER trg_set_professional_defaults_biur ON core.professionals';
  END IF;
END$$;

-- 2) Drop clearly-duplicated FKs (keep the *_id_fkey versions)
ALTER TABLE analytics.lead_submissions
  DROP CONSTRAINT IF EXISTS lead_submissions_customer_fk,
  DROP CONSTRAINT IF EXISTS lead_submissions_professional_fk,
  DROP CONSTRAINT IF EXISTS lead_submissions_site_fk;

ALTER TABLE analytics.link_clicks
  DROP CONSTRAINT IF EXISTS link_clicks_block_fk,
  DROP CONSTRAINT IF EXISTS link_clicks_professional_fk,
  DROP CONSTRAINT IF EXISTS link_clicks_site_fk;

ALTER TABLE analytics.site_visits
  DROP CONSTRAINT IF EXISTS site_visits_professional_fk,
  DROP CONSTRAINT IF EXISTS site_visits_site_fk;

-- 3) RLS policies for notifications & notification_receipts
-- Assumptions:
-- - Professionals map via core.professionals.auth_user_id = auth.uid()
-- - Staff are in core.comet_staff; admins have role='admin'
-- - Broadcast notifications have professional_id IS NULL

-- Ensure RLS is enabled (already true per report, but idempotent)
ALTER TABLE core.notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE core.notification_receipts ENABLE ROW LEVEL SECURITY;

-- Notifications policies
DO $$
BEGIN
  -- Professionals: can select their own + broadcast
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notifications' AND policyname='notifications_select_pro'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notifications_select_pro ON core.notifications
      FOR SELECT
      USING (
        professional_id IS NULL
        OR professional_id IN (
          SELECT p.id FROM core.professionals p
          WHERE p.auth_user_id = auth.uid()
            AND p.deleted_at IS NULL
        )
      );
    $p$;
  END IF;

  -- Staff (any role): can select all
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notifications' AND policyname='notifications_select_staff'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notifications_select_staff ON core.notifications
      FOR SELECT
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
        )
      );
    $p$;
  END IF;

  -- Staff admin: insert/update/delete
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notifications' AND policyname='notifications_write_admin'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notifications_write_admin ON core.notifications
      FOR ALL
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      )
      WITH CHECK (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      );
    $p$;
  END IF;
END$$;

-- Notification receipts policies
DO $$
BEGIN
  -- Professionals: select/insert/update/delete their own receipts
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notification_receipts' AND policyname='notification_receipts_pro_all'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notification_receipts_pro_all ON core.notification_receipts
      FOR ALL
      USING (
        professional_id IN (
          SELECT p.id FROM core.professionals p
          WHERE p.auth_user_id = auth.uid()
            AND p.deleted_at IS NULL
        )
      )
      WITH CHECK (
        professional_id IN (
          SELECT p.id FROM core.professionals p
          WHERE p.auth_user_id = auth.uid()
            AND p.deleted_at IS NULL
        )
      );
    $p$;
  END IF;

  -- Staff (any role): can SELECT all receipts
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notification_receipts' AND policyname='notification_receipts_staff_select'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notification_receipts_staff_select ON core.notification_receipts
      FOR SELECT
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
        )
      );
    $p$;
  END IF;

  -- Staff admin: can write all receipts
  IF NOT EXISTS (
    SELECT 1 FROM pg_policies
    WHERE schemaname='core' AND tablename='notification_receipts' AND policyname='notification_receipts_admin_write'
  ) THEN
    EXECUTE $p$
      CREATE POLICY notification_receipts_admin_write ON core.notification_receipts
      FOR ALL
      USING (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      )
      WITH CHECK (
        EXISTS (
          SELECT 1 FROM core.comet_staff cs
          WHERE cs.auth_user_id = auth.uid()
            AND cs.role = 'admin'
        )
      );
    $p$;
  END IF;
END$$;
-- === End 20260108100309_further_bug_fixes.sql ===
