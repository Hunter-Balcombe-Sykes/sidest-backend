-- 20260124150000_bookings_initial.sql
-- =========================================================================================
-- Initial bookings schema (full)
-- Includes: stores, store hours + closures, employees, services mapping, working hours,
-- time off, bookings (slot-aware concurrency), multi-service booking_items,
-- booking_settings, booking_payments, cross-professional integrity, soft deletion,
-- overlap constraints, indexes, RLS policies.
-- =========================================================================================

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;

BEGIN;

-- ---------------------------------------------------------------------
-- Extensions / schemas
-- ---------------------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS "extensions";

-- Needed for exclusion constraints (gist with uuid equality + range overlap)
CREATE EXTENSION IF NOT EXISTS "btree_gist" WITH SCHEMA "extensions";

-- Most Supabase projects already have pgcrypto (for gen_random_uuid, gen_random_bytes).
-- If yours doesn’t, uncomment the next line:
-- CREATE EXTENSION IF NOT EXISTS "pgcrypto" WITH SCHEMA "extensions";

-- ---------------------------------------------------------------------
-- Preflight: required columns exist on referenced tables
-- (needed for cross-professional integrity)
-- ---------------------------------------------------------------------
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema='core' AND table_name='services' AND column_name='professional_id'
  ) THEN
    RAISE EXCEPTION 'Missing core.services.professional_id. Required for cross-professional integrity.';
  END IF;

  IF NOT EXISTS (
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema='core' AND table_name='customers' AND column_name='professional_id'
  ) THEN
    RAISE EXCEPTION 'Missing core.customers.professional_id. Required for cross-professional integrity.';
  END IF;
END $$;

-- =========================================================================================
-- STORES
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."stores" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "professional_id" uuid NOT NULL,
  "name" text NOT NULL,
  "address" text,
  "email" text,
  "phone" text,
  "timezone" text,
  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz
);

ALTER TABLE "core"."stores" OWNER TO "postgres";

ALTER TABLE ONLY "core"."stores"
  ADD CONSTRAINT IF NOT EXISTS "stores_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."stores"
  ADD CONSTRAINT IF NOT EXISTS "stores_professional_id_fkey"
  FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "stores_professional_id_idx"
  ON "core"."stores" ("professional_id");

-- Composite unique for cross-professional FKs (store_id, professional_id)
CREATE UNIQUE INDEX IF NOT EXISTS "stores_id_professional_uidx"
  ON "core"."stores" ("id","professional_id");

DROP TRIGGER IF EXISTS "set_timestamp_stores" ON "core"."stores";
CREATE TRIGGER "set_timestamp_stores"
  BEFORE UPDATE ON "core"."stores"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- =========================================================================================
-- STORE OPENING HOURS (weekly)
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."store_working_hours" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "store_id" uuid NOT NULL,
  "day_of_week" smallint NOT NULL,
  "start_time" time NOT NULL,
  "end_time" time NOT NULL,
  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,
  CONSTRAINT "store_working_hours_day_check" CHECK ("day_of_week" BETWEEN 0 AND 6),
  CONSTRAINT "store_working_hours_time_check" CHECK ("end_time" > "start_time")
);

ALTER TABLE "core"."store_working_hours" OWNER TO "postgres";

ALTER TABLE ONLY "core"."store_working_hours"
  ADD CONSTRAINT IF NOT EXISTS "store_working_hours_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."store_working_hours"
  ADD CONSTRAINT IF NOT EXISTS "store_working_hours_store_id_fkey"
  FOREIGN KEY ("store_id") REFERENCES "core"."stores"("id") ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "store_working_hours_store_day_idx"
  ON "core"."store_working_hours" ("store_id","day_of_week");

DROP TRIGGER IF EXISTS "set_timestamp_store_working_hours" ON "core"."store_working_hours";
CREATE TRIGGER "set_timestamp_store_working_hours"
  BEFORE UPDATE ON "core"."store_working_hours"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- Prevent overlapping store hours per day (allows split shifts, blocks overlaps)
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'store_working_hours_no_overlap') THEN
    ALTER TABLE "core"."store_working_hours"
      ADD CONSTRAINT "store_working_hours_no_overlap"
      EXCLUDE USING gist (
        "store_id" WITH =,
        "day_of_week" WITH =,
        tsrange(
          ('2000-01-01'::date + "start_time"),
          ('2000-01-01'::date + "end_time"),
          '[)'
        ) WITH &&
      )
      WHERE ("deleted_at" IS NULL);
  END IF;
END $$;

-- =========================================================================================
-- STORE CLOSURES / EXCEPTIONS
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."store_time_off" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "store_id" uuid NOT NULL,
  "start_at" timestamptz NOT NULL,
  "end_at" timestamptz NOT NULL,
  "reason" text,
  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,
  CONSTRAINT "store_time_off_time_check" CHECK ("end_at" > "start_at")
);

ALTER TABLE "core"."store_time_off" OWNER TO "postgres";

ALTER TABLE ONLY "core"."store_time_off"
  ADD CONSTRAINT IF NOT EXISTS "store_time_off_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."store_time_off"
  ADD CONSTRAINT IF NOT EXISTS "store_time_off_store_id_fkey"
  FOREIGN KEY ("store_id") REFERENCES "core"."stores"("id") ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "store_time_off_store_id_idx"
  ON "core"."store_time_off" ("store_id","start_at");

DROP TRIGGER IF EXISTS "set_timestamp_store_time_off" ON "core"."store_time_off";
CREATE TRIGGER "set_timestamp_store_time_off"
  BEFORE UPDATE ON "core"."store_time_off"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- Prevent overlapping closures per store
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'store_time_off_no_overlap') THEN
    ALTER TABLE "core"."store_time_off"
      ADD CONSTRAINT "store_time_off_no_overlap"
      EXCLUDE USING gist (
        "store_id" WITH =,
        tstzrange("start_at","end_at",'[)') WITH &&
      )
      WHERE ("deleted_at" IS NULL);
  END IF;
END $$;

-- =========================================================================================
-- EMPLOYEES
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."employees" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "professional_id" uuid NOT NULL,
  "store_id" uuid,
  "full_name" text NOT NULL,
  "email" text,
  "phone" text,
  "timezone" text,
  "joined_date" date,

  -- concurrency capacity (1=normal; 2+ enables parallel appointments)
  "max_concurrent_bookings" smallint NOT NULL DEFAULT 1
    CHECK ("max_concurrent_bookings" BETWEEN 1 AND 5),

  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz
);

ALTER TABLE "core"."employees" OWNER TO "postgres";

ALTER TABLE ONLY "core"."employees"
  ADD CONSTRAINT IF NOT EXISTS "employees_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."employees"
  ADD CONSTRAINT IF NOT EXISTS "employees_professional_id_fkey"
  FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;

ALTER TABLE ONLY "core"."employees"
  ADD CONSTRAINT IF NOT EXISTS "employees_store_id_fkey"
  FOREIGN KEY ("store_id") REFERENCES "core"."stores"("id") ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS "employees_professional_id_idx"
  ON "core"."employees" ("professional_id");

CREATE INDEX IF NOT EXISTS "employees_store_id_idx"
  ON "core"."employees" ("store_id");

-- Composite unique for cross-professional FKs (employee_id, professional_id)
CREATE UNIQUE INDEX IF NOT EXISTS "employees_id_professional_uidx"
  ON "core"."employees" ("id","professional_id");

DROP TRIGGER IF EXISTS "set_timestamp_employees" ON "core"."employees";
CREATE TRIGGER "set_timestamp_employees"
  BEFORE UPDATE ON "core"."employees"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- =========================================================================================
-- SERVICES + CUSTOMERS composite unique indexes (needed for composite FK references)
-- =========================================================================================
CREATE UNIQUE INDEX IF NOT EXISTS "services_id_professional_uidx"
  ON "core"."services" ("id","professional_id");

CREATE UNIQUE INDEX IF NOT EXISTS "customers_id_professional_uidx"
  ON "core"."customers" ("id","professional_id");

-- =========================================================================================
-- EMPLOYEE <-> SERVICES (M2M, cross-tenant safe)
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."employee_services" (
  "professional_id" uuid NOT NULL,
  "employee_id" uuid NOT NULL,
  "service_id" uuid NOT NULL,
  "created_at" timestamptz DEFAULT now() NOT NULL,
  CONSTRAINT "employee_services_pkey" PRIMARY KEY ("employee_id","service_id")
);

ALTER TABLE "core"."employee_services" OWNER TO "postgres";

ALTER TABLE ONLY "core"."employee_services"
  ADD CONSTRAINT IF NOT EXISTS "employee_services_employee_professional_fkey"
  FOREIGN KEY ("employee_id","professional_id")
  REFERENCES "core"."employees"("id","professional_id")
  ON DELETE CASCADE;

ALTER TABLE ONLY "core"."employee_services"
  ADD CONSTRAINT IF NOT EXISTS "employee_services_service_professional_fkey"
  FOREIGN KEY ("service_id","professional_id")
  REFERENCES "core"."services"("id","professional_id")
  ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "employee_services_professional_id_idx"
  ON "core"."employee_services" ("professional_id");

CREATE INDEX IF NOT EXISTS "employee_services_service_id_idx"
  ON "core"."employee_services" ("service_id");

-- =========================================================================================
-- EMPLOYEE WORKING HOURS
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."employee_working_hours" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "employee_id" uuid NOT NULL,
  "day_of_week" smallint NOT NULL,
  "start_time" time NOT NULL,
  "end_time" time NOT NULL,
  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,
  CONSTRAINT "employee_working_hours_day_check" CHECK ("day_of_week" BETWEEN 0 AND 6),
  CONSTRAINT "employee_working_hours_time_check" CHECK ("end_time" > "start_time")
);

ALTER TABLE "core"."employee_working_hours" OWNER TO "postgres";

ALTER TABLE ONLY "core"."employee_working_hours"
  ADD CONSTRAINT IF NOT EXISTS "employee_working_hours_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."employee_working_hours"
  ADD CONSTRAINT IF NOT EXISTS "employee_working_hours_employee_id_fkey"
  FOREIGN KEY ("employee_id") REFERENCES "core"."employees"("id") ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "employee_working_hours_employee_day_idx"
  ON "core"."employee_working_hours" ("employee_id","day_of_week");

DROP TRIGGER IF EXISTS "set_timestamp_employee_working_hours" ON "core"."employee_working_hours";
CREATE TRIGGER "set_timestamp_employee_working_hours"
  BEFORE UPDATE ON "core"."employee_working_hours"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- Prevent overlapping working hours per employee per day
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'employee_working_hours_no_overlap') THEN
    ALTER TABLE "core"."employee_working_hours"
      ADD CONSTRAINT "employee_working_hours_no_overlap"
      EXCLUDE USING gist (
        "employee_id" WITH =,
        "day_of_week" WITH =,
        tsrange(
          ('2000-01-01'::date + "start_time"),
          ('2000-01-01'::date + "end_time"),
          '[)'
        ) WITH &&
      )
      WHERE ("deleted_at" IS NULL);
  END IF;
END $$;

-- =========================================================================================
-- EMPLOYEE TIME OFF
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."employee_time_off" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "employee_id" uuid NOT NULL,
  "start_at" timestamptz NOT NULL,
  "end_at" timestamptz NOT NULL,
  "reason" text,
  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,
  CONSTRAINT "employee_time_off_time_check" CHECK ("end_at" > "start_at")
);

ALTER TABLE "core"."employee_time_off" OWNER TO "postgres";

ALTER TABLE ONLY "core"."employee_time_off"
  ADD CONSTRAINT IF NOT EXISTS "employee_time_off_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."employee_time_off"
  ADD CONSTRAINT IF NOT EXISTS "employee_time_off_employee_id_fkey"
  FOREIGN KEY ("employee_id") REFERENCES "core"."employees"("id") ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "employee_time_off_employee_id_idx"
  ON "core"."employee_time_off" ("employee_id","start_at");

DROP TRIGGER IF EXISTS "set_timestamp_employee_time_off" ON "core"."employee_time_off";
CREATE TRIGGER "set_timestamp_employee_time_off"
  BEFORE UPDATE ON "core"."employee_time_off"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- Prevent overlapping time off per employee
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'employee_time_off_no_overlap') THEN
    ALTER TABLE "core"."employee_time_off"
      ADD CONSTRAINT "employee_time_off_no_overlap"
      EXCLUDE USING gist (
        "employee_id" WITH =,
        tstzrange("start_at","end_at",'[)') WITH &&
      )
      WHERE ("deleted_at" IS NULL);
  END IF;
END $$;

-- =========================================================================================
-- BOOKINGS
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."bookings" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "professional_id" uuid NOT NULL,

  "store_id" uuid,
  "employee_id" uuid NOT NULL,
  "customer_id" uuid NOT NULL,

  -- Nullable when using multi-service booking_items
  "service_id" uuid,

  "start_at" timestamptz NOT NULL,
  "end_at" timestamptz NOT NULL,

  -- Lane for concurrency (slot 1..N)
  "concurrency_slot" smallint NOT NULL DEFAULT 1
    CHECK ("concurrency_slot" BETWEEN 1 AND 5),

  "status" text NOT NULL DEFAULT 'confirmed',
  "source" text NOT NULL DEFAULT 'site',

  "public_token" character varying(80),

  -- snapshots (kept for single-service bookings; multi-service snapshots live in booking_items)
  "service_title" text,
  "service_duration_minutes" integer,
  "service_price_cents" integer,
  "service_currency_code" character(3),

  "customer_note" text,
  "internal_note" text,

  "cancelled_at" timestamptz,
  "cancel_reason" text,
  "cancelled_by" text,

  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,

  CONSTRAINT "bookings_time_check" CHECK ("end_at" > "start_at"),
  CONSTRAINT "bookings_status_check" CHECK ("status" = ANY (ARRAY['pending','confirmed','cancelled','completed','no_show'])),
  CONSTRAINT "bookings_source_check" CHECK ("source" = ANY (ARRAY['site','professional','staff']))
);

ALTER TABLE "core"."bookings" OWNER TO "postgres";

ALTER TABLE ONLY "core"."bookings"
  ADD CONSTRAINT IF NOT EXISTS "bookings_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."bookings"
  ADD CONSTRAINT IF NOT EXISTS "bookings_professional_id_fkey"
  FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE;

-- Cross-professional safe composite FKs
ALTER TABLE ONLY "core"."bookings"
  ADD CONSTRAINT IF NOT EXISTS "bookings_store_professional_fkey"
  FOREIGN KEY ("store_id","professional_id")
  REFERENCES "core"."stores"("id","professional_id")
  ON DELETE SET NULL;

ALTER TABLE ONLY "core"."bookings"
  ADD CONSTRAINT IF NOT EXISTS "bookings_employee_professional_fkey"
  FOREIGN KEY ("employee_id","professional_id")
  REFERENCES "core"."employees"("id","professional_id")
  ON DELETE RESTRICT;

ALTER TABLE ONLY "core"."bookings"
  ADD CONSTRAINT IF NOT EXISTS "bookings_customer_professional_fkey"
  FOREIGN KEY ("customer_id","professional_id")
  REFERENCES "core"."customers"("id","professional_id")
  ON DELETE RESTRICT;

ALTER TABLE ONLY "core"."bookings"
  ADD CONSTRAINT IF NOT EXISTS "bookings_service_professional_fkey"
  FOREIGN KEY ("service_id","professional_id")
  REFERENCES "core"."services"("id","professional_id")
  ON DELETE RESTRICT;

-- Composite unique for (booking_id, professional_id)
CREATE UNIQUE INDEX IF NOT EXISTS "bookings_id_professional_uidx"
  ON "core"."bookings" ("id","professional_id");

-- Indexes
CREATE INDEX IF NOT EXISTS "bookings_professional_start_idx"
  ON "core"."bookings" ("professional_id","start_at");

CREATE INDEX IF NOT EXISTS "bookings_employee_start_idx"
  ON "core"."bookings" ("employee_id","start_at");

CREATE INDEX IF NOT EXISTS "bookings_employee_start_status_idx"
  ON "core"."bookings" ("employee_id","start_at","status");

CREATE INDEX IF NOT EXISTS "bookings_store_start_idx"
  ON "core"."bookings" ("store_id","start_at");

CREATE INDEX IF NOT EXISTS "bookings_customer_start_idx"
  ON "core"."bookings" ("customer_id","start_at");

CREATE INDEX IF NOT EXISTS "bookings_status_idx"
  ON "core"."bookings" ("status");

CREATE UNIQUE INDEX IF NOT EXISTS "bookings_public_token_unique"
  ON "core"."bookings" ("public_token")
  WHERE "public_token" IS NOT NULL;

DROP TRIGGER IF EXISTS "set_timestamp_bookings" ON "core"."bookings";
CREATE TRIGGER "set_timestamp_bookings"
  BEFORE UPDATE ON "core"."bookings"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- Slot-aware anti double-booking constraint (pending + confirmed, not deleted)
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'bookings_no_overlap_employee_slot') THEN
    ALTER TABLE "core"."bookings"
      ADD CONSTRAINT "bookings_no_overlap_employee_slot"
      EXCLUDE USING gist (
        "employee_id" WITH =,
        "concurrency_slot" WITH =,
        tstzrange("start_at","end_at",'[)') WITH &&
      )
      WHERE (
        "deleted_at" IS NULL
        AND "status" = ANY (ARRAY['pending','confirmed'])
      );
  END IF;
END $$;

-- =========================================================================================
-- MULTI-SERVICE APPOINTMENTS (booking_items)
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."booking_items" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "professional_id" uuid NOT NULL,
  "booking_id" uuid NOT NULL,

  "service_id" uuid NOT NULL,
  "sort_order" integer NOT NULL DEFAULT 0,

  -- snapshots
  "service_title" text,
  "service_duration_minutes" integer,
  "service_price_cents" integer,
  "service_currency_code" character(3),

  "buffer_before_minutes" integer NOT NULL DEFAULT 0,
  "buffer_after_minutes" integer NOT NULL DEFAULT 0,

  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,

  CONSTRAINT "booking_items_duration_check"
    CHECK ("service_duration_minutes" IS NULL OR "service_duration_minutes" > 0),
  CONSTRAINT "booking_items_buffers_check"
    CHECK ("buffer_before_minutes" >= 0 AND "buffer_after_minutes" >= 0)
);

ALTER TABLE "core"."booking_items" OWNER TO "postgres";

ALTER TABLE ONLY "core"."booking_items"
  ADD CONSTRAINT IF NOT EXISTS "booking_items_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."booking_items"
  ADD CONSTRAINT IF NOT EXISTS "booking_items_booking_professional_fkey"
  FOREIGN KEY ("booking_id","professional_id")
  REFERENCES "core"."bookings"("id","professional_id")
  ON DELETE CASCADE;

ALTER TABLE ONLY "core"."booking_items"
  ADD CONSTRAINT IF NOT EXISTS "booking_items_service_professional_fkey"
  FOREIGN KEY ("service_id","professional_id")
  REFERENCES "core"."services"("id","professional_id")
  ON DELETE RESTRICT;

CREATE INDEX IF NOT EXISTS "booking_items_booking_idx"
  ON "core"."booking_items" ("booking_id","sort_order");

DROP TRIGGER IF EXISTS "set_timestamp_booking_items" ON "core"."booking_items";
CREATE TRIGGER "set_timestamp_booking_items"
  BEFORE UPDATE ON "core"."booking_items"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- =========================================================================================
-- BOOKING SETTINGS (professional default + store override)
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."booking_settings" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "professional_id" uuid NOT NULL,
  "store_id" uuid,

  "slot_interval_minutes" smallint NOT NULL DEFAULT 15
    CHECK ("slot_interval_minutes" IN (5,10,15,20,30,60)),
  "min_notice_minutes" integer NOT NULL DEFAULT 0
    CHECK ("min_notice_minutes" >= 0),
  "max_advance_days" integer NOT NULL DEFAULT 365
    CHECK ("max_advance_days" >= 0),

  "buffer_before_minutes" integer NOT NULL DEFAULT 0 CHECK ("buffer_before_minutes" >= 0),
  "buffer_after_minutes" integer NOT NULL DEFAULT 0 CHECK ("buffer_after_minutes" >= 0),

  "allow_customer_cancel" boolean NOT NULL DEFAULT true,
  "allow_customer_reschedule" boolean NOT NULL DEFAULT true,
  "cancellation_cutoff_minutes" integer NOT NULL DEFAULT 0 CHECK ("cancellation_cutoff_minutes" >= 0),

  "require_deposit" boolean NOT NULL DEFAULT false,
  "deposit_amount_cents" integer,
  "deposit_currency_code" character(3),

  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz,

  CONSTRAINT "booking_settings_professional_fkey"
    FOREIGN KEY ("professional_id") REFERENCES "core"."professionals"("id") ON DELETE CASCADE,

  CONSTRAINT "booking_settings_store_professional_fkey"
    FOREIGN KEY ("store_id","professional_id")
    REFERENCES "core"."stores"("id","professional_id")
    ON DELETE CASCADE,

  CONSTRAINT "booking_settings_deposit_check"
    CHECK (
      ("require_deposit" = false AND "deposit_amount_cents" IS NULL AND "deposit_currency_code" IS NULL)
      OR
      ("require_deposit" = true AND "deposit_amount_cents" IS NOT NULL AND "deposit_amount_cents" > 0 AND "deposit_currency_code" IS NOT NULL)
    )
);

ALTER TABLE "core"."booking_settings" OWNER TO "postgres";

ALTER TABLE ONLY "core"."booking_settings"
  ADD CONSTRAINT IF NOT EXISTS "booking_settings_pkey" PRIMARY KEY ("id");

CREATE UNIQUE INDEX IF NOT EXISTS "booking_settings_default_professional_uidx"
  ON "core"."booking_settings" ("professional_id")
  WHERE "store_id" IS NULL AND "deleted_at" IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS "booking_settings_store_uidx"
  ON "core"."booking_settings" ("professional_id","store_id")
  WHERE "store_id" IS NOT NULL AND "deleted_at" IS NULL;

DROP TRIGGER IF EXISTS "set_timestamp_booking_settings" ON "core"."booking_settings";
CREATE TRIGGER "set_timestamp_booking_settings"
  BEFORE UPDATE ON "core"."booking_settings"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- =========================================================================================
-- PAYMENTS LEDGER
-- =========================================================================================
CREATE TABLE IF NOT EXISTS "core"."booking_payments" (
  "id" uuid DEFAULT "gen_random_uuid"() NOT NULL,
  "professional_id" uuid NOT NULL,
  "booking_id" uuid NOT NULL,

  "amount_cents" integer NOT NULL CHECK ("amount_cents" > 0),
  "currency_code" character(3) NOT NULL,

  "method" text NOT NULL CHECK ("method" = ANY (ARRAY['cash','card','bank_transfer','other'])),
  "status" text NOT NULL DEFAULT 'paid' CHECK ("status" = ANY (ARRAY['paid','refunded','void'])),

  "provider" text,
  "provider_ref" text,
  "paid_at" timestamptz DEFAULT now() NOT NULL,

  "created_at" timestamptz DEFAULT now() NOT NULL,
  "updated_at" timestamptz DEFAULT now() NOT NULL,
  "deleted_at" timestamptz
);

ALTER TABLE "core"."booking_payments" OWNER TO "postgres";

ALTER TABLE ONLY "core"."booking_payments"
  ADD CONSTRAINT IF NOT EXISTS "booking_payments_pkey" PRIMARY KEY ("id");

ALTER TABLE ONLY "core"."booking_payments"
  ADD CONSTRAINT IF NOT EXISTS "booking_payments_booking_professional_fkey"
  FOREIGN KEY ("booking_id","professional_id")
  REFERENCES "core"."bookings"("id","professional_id")
  ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS "booking_payments_booking_idx"
  ON "core"."booking_payments" ("booking_id");

CREATE INDEX IF NOT EXISTS "booking_payments_professional_paid_idx"
  ON "core"."booking_payments" ("professional_id","paid_at");

DROP TRIGGER IF EXISTS "set_timestamp_booking_payments" ON "core"."booking_payments";
CREATE TRIGGER "set_timestamp_booking_payments"
  BEFORE UPDATE ON "core"."booking_payments"
  FOR EACH ROW EXECUTE FUNCTION "public"."set_updated_at"();

-- =========================================================================================
-- RLS
-- =========================================================================================
ALTER TABLE "core"."stores" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."store_working_hours" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."store_time_off" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."employees" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."employee_services" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."employee_working_hours" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."employee_time_off" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."bookings" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."booking_items" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."booking_settings" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "core"."booking_payments" ENABLE ROW LEVEL SECURITY;

-- STORES
DROP POLICY IF EXISTS "stores_pro_all" ON "core"."stores";
CREATE POLICY "stores_pro_all" ON "core"."stores" TO "authenticated"
USING (
  stores.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM core.professionals p
      WHERE p.id = stores.professional_id
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = stores.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- EMPLOYEES
DROP POLICY IF EXISTS "employees_pro_all" ON "core"."employees";
CREATE POLICY "employees_pro_all" ON "core"."employees" TO "authenticated"
USING (
  employees.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM core.professionals p
      WHERE p.id = employees.professional_id
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = employees.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- EMPLOYEE_SERVICES
DROP POLICY IF EXISTS "employee_services_pro_all" ON "core"."employee_services";
CREATE POLICY "employee_services_pro_all" ON "core"."employee_services" TO "authenticated"
USING (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = employee_services.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = employee_services.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- EMPLOYEE WORKING HOURS
DROP POLICY IF EXISTS "employee_working_hours_pro_all" ON "core"."employee_working_hours";
CREATE POLICY "employee_working_hours_pro_all" ON "core"."employee_working_hours" TO "authenticated"
USING (
  employee_working_hours.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1
      FROM core.employees e
      JOIN core.professionals p ON p.id = e.professional_id
      WHERE e.id = employee_working_hours.employee_id
        AND e.deleted_at IS NULL
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1
    FROM core.employees e
    JOIN core.professionals p ON p.id = e.professional_id
    WHERE e.id = employee_working_hours.employee_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- EMPLOYEE TIME OFF
DROP POLICY IF EXISTS "employee_time_off_pro_all" ON "core"."employee_time_off";
CREATE POLICY "employee_time_off_pro_all" ON "core"."employee_time_off" TO "authenticated"
USING (
  employee_time_off.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1
      FROM core.employees e
      JOIN core.professionals p ON p.id = e.professional_id
      WHERE e.id = employee_time_off.employee_id
        AND e.deleted_at IS NULL
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1
    FROM core.employees e
    JOIN core.professionals p ON p.id = e.professional_id
    WHERE e.id = employee_time_off.employee_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- STORE WORKING HOURS
DROP POLICY IF EXISTS "store_working_hours_pro_all" ON "core"."store_working_hours";
CREATE POLICY "store_working_hours_pro_all" ON "core"."store_working_hours" TO "authenticated"
USING (
  store_working_hours.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1
      FROM core.stores s
      JOIN core.professionals p ON p.id = s.professional_id
      WHERE s.id = store_working_hours.store_id
        AND s.deleted_at IS NULL
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1
    FROM core.stores s
    JOIN core.professionals p ON p.id = s.professional_id
    WHERE s.id = store_working_hours.store_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- STORE TIME OFF
DROP POLICY IF EXISTS "store_time_off_pro_all" ON "core"."store_time_off";
CREATE POLICY "store_time_off_pro_all" ON "core"."store_time_off" TO "authenticated"
USING (
  store_time_off.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1
      FROM core.stores s
      JOIN core.professionals p ON p.id = s.professional_id
      WHERE s.id = store_time_off.store_id
        AND s.deleted_at IS NULL
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1
    FROM core.stores s
    JOIN core.professionals p ON p.id = s.professional_id
    WHERE s.id = store_time_off.store_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- BOOKINGS
DROP POLICY IF EXISTS "bookings_pro_all" ON "core"."bookings";
CREATE POLICY "bookings_pro_all" ON "core"."bookings" TO "authenticated"
USING (
  bookings.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM core.professionals p
      WHERE p.id = bookings.professional_id
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = bookings.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- BOOKING ITEMS
DROP POLICY IF EXISTS "booking_items_pro_all" ON "core"."booking_items";
CREATE POLICY "booking_items_pro_all" ON "core"."booking_items" TO "authenticated"
USING (
  booking_items.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM core.professionals p
      WHERE p.id = booking_items.professional_id
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = booking_items.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- BOOKING SETTINGS
DROP POLICY IF EXISTS "booking_settings_pro_all" ON "core"."booking_settings";
CREATE POLICY "booking_settings_pro_all" ON "core"."booking_settings" TO "authenticated"
USING (
  booking_settings.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM core.professionals p
      WHERE p.id = booking_settings.professional_id
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = booking_settings.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- BOOKING PAYMENTS
DROP POLICY IF EXISTS "booking_payments_pro_all" ON "core"."booking_payments";
CREATE POLICY "booking_payments_pro_all" ON "core"."booking_payments" TO "authenticated"
USING (
  booking_payments.deleted_at IS NULL
  AND (
    EXISTS (
      SELECT 1 FROM core.professionals p
      WHERE p.id = booking_payments.professional_id
        AND p.auth_user_id = auth.uid()
        AND p.deleted_at IS NULL
    )
    OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
  )
)
WITH CHECK (
  EXISTS (
    SELECT 1 FROM core.professionals p
    WHERE p.id = booking_payments.professional_id
      AND p.auth_user_id = auth.uid()
      AND p.deleted_at IS NULL
  )
  OR EXISTS (SELECT 1 FROM core.comet_staff cs WHERE cs.auth_user_id = auth.uid())
);

-- =========================================================================================
-- HOURS WORKED helper function (excludes deleted bookings)
-- =========================================================================================
CREATE OR REPLACE FUNCTION "core"."employee_minutes_worked"(
  "p_employee_id" uuid,
  "p_from" timestamptz,
  "p_to" timestamptz
) RETURNS bigint
LANGUAGE sql
STABLE
AS $$
  SELECT COALESCE(
    SUM(
      EXTRACT(EPOCH FROM (LEAST(b.end_at, p_to) - GREATEST(b.start_at, p_from))) / 60
    )::bigint,
    0
  )
  FROM core.bookings b
  WHERE b.employee_id = p_employee_id
    AND b.deleted_at IS NULL
    AND b.status = 'completed'
    AND b.start_at < p_to
    AND b.end_at > p_from;
$$;

-- =========================================================================================
-- Grants (match your existing pattern)
-- =========================================================================================
GRANT USAGE ON SCHEMA "core" TO "app_backend";
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA "core" TO "app_backend";

COMMIT;
