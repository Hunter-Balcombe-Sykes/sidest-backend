-- Create/update promoter account for Natalie Anne Hair.
-- Idempotent: safe to run multiple times.

BEGIN;

DO $$
DECLARE
    v_email                 text := 'natalieanne@hair.com';
    v_auth_email_alt        text := 'natalieann@hair.com';
    v_phone                 text := '+61492475233';
    v_handle                text := 'natalie-anne-hair';
    v_subdomain             text := 'natalie-anne-hair';
    v_display_name          text := 'Natalie Anne Hair';
    v_first_name            text := 'Natalie Anne';
    v_last_name             text := 'Ayoub';
    v_professional_id       uuid := '20000000-0000-0000-0000-000000000006';
    v_enterprise_id         uuid := '10000000-0000-0000-0000-000000000005';
    v_membership_id         uuid := '40000000-0000-0000-0000-000000000007';
    v_auth_user_id          uuid;
    v_existing_professional uuid;
    v_existing_enterprise   uuid;
    v_existing_site         uuid;
    v_rows_updated          integer := 0;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'professionals'
    ) THEN
        RAISE EXCEPTION 'core.professionals table does not exist.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'enterprises'
    ) THEN
        RAISE EXCEPTION 'core.enterprises table does not exist. Run enterprise migrations first.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'professional_enterprise_memberships'
    ) THEN
        RAISE EXCEPTION 'core.professional_enterprise_memberships table does not exist. Run enterprise migrations first.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'sites'
    ) THEN
        RAISE EXCEPTION 'core.sites table does not exist.';
    END IF;

    -- Prefer a real Supabase auth user by email if present.
    SELECT u.id
      INTO v_auth_user_id
      FROM auth.users u
     WHERE lower(u.email) IN (v_email, v_auth_email_alt)
     ORDER BY
        CASE
            WHEN lower(u.email) = v_email THEN 0
            ELSE 1
        END,
        u.created_at DESC
     LIMIT 1;

    -- Fallback: if a profile already exists with this email and valid auth user, reuse it.
    IF v_auth_user_id IS NULL THEN
        SELECT p.auth_user_id
          INTO v_auth_user_id
          FROM core.professionals p
          JOIN auth.users u
            ON u.id = p.auth_user_id
         WHERE lower(COALESCE(p.primary_email, '')) = v_email
         ORDER BY p.created_at DESC
         LIMIT 1;
    END IF;

    IF v_auth_user_id IS NULL THEN
        RAISE EXCEPTION
            'No auth.users row found for % (or %). Create this user in Supabase Auth first, then rerun this migration.',
            v_email,
            v_auth_email_alt;
    END IF;

    -- Reuse existing professional where possible.
    SELECT p.id
      INTO v_existing_professional
      FROM core.professionals p
     WHERE p.auth_user_id = v_auth_user_id
        OR lower(COALESCE(p.primary_email, '')) = v_email
     ORDER BY (p.auth_user_id = v_auth_user_id) DESC, p.created_at DESC
     LIMIT 1;

    IF v_existing_professional IS NOT NULL THEN
        v_professional_id := v_existing_professional;
    END IF;

    -- Reuse existing enterprise where possible.
    SELECT e.id
      INTO v_existing_enterprise
      FROM core.enterprises e
     WHERE e.deleted_at IS NULL
       AND (
            e.auth_user_id = v_auth_user_id
            OR (
                e.enterprise_type = 'promoter'
                AND (
                    lower(COALESCE(e.primary_email, '')) = v_email
                    OR lower(COALESCE(e.handle, '')) = v_handle
                )
            )
       )
     ORDER BY (e.auth_user_id = v_auth_user_id) DESC, e.created_at DESC
     LIMIT 1;

    IF v_existing_enterprise IS NOT NULL THEN
        v_enterprise_id := v_existing_enterprise;
    END IF;

    INSERT INTO core.enterprises (
        id,
        auth_user_id,
        name,
        handle,
        primary_email,
        phone,
        public_contact_email,
        public_contact_number,
        country_code,
        timezone,
        location_street_address,
        location_city,
        location_state,
        location_postcode,
        location_country,
        enterprise_type,
        status,
        subscription_tier,
        metadata,
        deleted_at
    )
    VALUES (
        v_enterprise_id,
        v_auth_user_id,
        v_display_name,
        v_handle,
        v_email,
        v_phone,
        v_email,
        v_phone,
        'AU',
        'Australia/Sydney',
        'Unit 28 85/115 Alfred Rd',
        'Chipping Norton',
        'NSW',
        '2170',
        'Australia',
        'promoter',
        'active',
        'enterprise',
        jsonb_build_object('source', 'migration_20260312050000_add_natalie_anne_hair_promoter_account'),
        NULL
    )
    ON CONFLICT (id) DO UPDATE
    SET
        auth_user_id = EXCLUDED.auth_user_id,
        name = EXCLUDED.name,
        handle = EXCLUDED.handle,
        primary_email = EXCLUDED.primary_email,
        phone = EXCLUDED.phone,
        public_contact_email = EXCLUDED.public_contact_email,
        public_contact_number = EXCLUDED.public_contact_number,
        country_code = EXCLUDED.country_code,
        timezone = EXCLUDED.timezone,
        location_street_address = EXCLUDED.location_street_address,
        location_city = EXCLUDED.location_city,
        location_state = EXCLUDED.location_state,
        location_postcode = EXCLUDED.location_postcode,
        location_country = EXCLUDED.location_country,
        enterprise_type = EXCLUDED.enterprise_type,
        status = EXCLUDED.status,
        subscription_tier = EXCLUDED.subscription_tier,
        metadata = COALESCE(core.enterprises.metadata, '{}'::jsonb) || EXCLUDED.metadata,
        deleted_at = NULL;

    INSERT INTO core.professionals (
        id,
        auth_user_id,
        handle,
        display_name,
        bio,
        country_code,
        timezone,
        professional_type,
        status,
        onboarding_step,
        phone,
        primary_email,
        first_name,
        last_name,
        public_contact_number,
        public_contact_email,
        location_street_address,
        location_city,
        location_state,
        location_postcode,
        location_country,
        handle_lc,
        qr_slug,
        primary_enterprise_id,
        deleted_at
    )
    VALUES (
        v_professional_id,
        v_auth_user_id,
        v_handle,
        v_display_name,
        'Sydney-based colour specialist, educator, and founder of NA Haircare.',
        'AU',
        'Australia/Sydney',
        'promoter',
        'active',
        0,
        v_phone,
        v_email,
        v_first_name,
        v_last_name,
        v_phone,
        v_email,
        'Unit 28 85/115 Alfred Rd',
        'Chipping Norton',
        'NSW',
        '2170',
        'Australia',
        v_handle,
        'natalie-anne-hair-seed',
        v_enterprise_id,
        NULL
    )
    ON CONFLICT (id) DO UPDATE
    SET
        auth_user_id = EXCLUDED.auth_user_id,
        handle = EXCLUDED.handle,
        display_name = EXCLUDED.display_name,
        bio = EXCLUDED.bio,
        country_code = EXCLUDED.country_code,
        timezone = EXCLUDED.timezone,
        professional_type = EXCLUDED.professional_type,
        status = EXCLUDED.status,
        onboarding_step = EXCLUDED.onboarding_step,
        phone = EXCLUDED.phone,
        primary_email = EXCLUDED.primary_email,
        first_name = EXCLUDED.first_name,
        last_name = EXCLUDED.last_name,
        public_contact_number = EXCLUDED.public_contact_number,
        public_contact_email = EXCLUDED.public_contact_email,
        location_street_address = EXCLUDED.location_street_address,
        location_city = EXCLUDED.location_city,
        location_state = EXCLUDED.location_state,
        location_postcode = EXCLUDED.location_postcode,
        location_country = EXCLUDED.location_country,
        handle_lc = EXCLUDED.handle_lc,
        qr_slug = EXCLUDED.qr_slug,
        primary_enterprise_id = EXCLUDED.primary_enterprise_id,
        deleted_at = NULL;

    -- Keep the relationship active as owner/primary.
    UPDATE core.professional_enterprise_memberships m
       SET relationship_type = 'owner',
           is_primary = true,
           starts_at = COALESCE(m.starts_at, now()),
           ends_at = NULL,
           metadata = COALESCE(m.metadata, '{}'::jsonb) ||
               jsonb_build_object('source', 'migration_20260312050000_add_natalie_anne_hair_promoter_account')
     WHERE m.professional_id = v_professional_id
       AND m.enterprise_id = v_enterprise_id
       AND m.ends_at IS NULL;

    GET DIAGNOSTICS v_rows_updated = ROW_COUNT;

    IF v_rows_updated = 0 THEN
        INSERT INTO core.professional_enterprise_memberships (
            id,
            professional_id,
            enterprise_id,
            relationship_type,
            is_primary,
            starts_at,
            ends_at,
            metadata
        )
        VALUES (
            v_membership_id,
            v_professional_id,
            v_enterprise_id,
            'owner',
            true,
            now(),
            NULL,
            jsonb_build_object('source', 'migration_20260312050000_add_natalie_anne_hair_promoter_account')
        )
        ON CONFLICT (id) DO UPDATE
        SET
            professional_id = EXCLUDED.professional_id,
            enterprise_id = EXCLUDED.enterprise_id,
            relationship_type = EXCLUDED.relationship_type,
            is_primary = EXCLUDED.is_primary,
            starts_at = EXCLUDED.starts_at,
            ends_at = EXCLUDED.ends_at,
            metadata = EXCLUDED.metadata;
    END IF;

    UPDATE core.professional_enterprise_memberships
       SET is_primary = false
     WHERE professional_id = v_professional_id
       AND enterprise_id <> v_enterprise_id
       AND ends_at IS NULL
       AND is_primary = true;

    UPDATE core.professionals
       SET primary_enterprise_id = v_enterprise_id
     WHERE id = v_professional_id;

    -- Ensure a site exists for dashboard/public payload flows.
    SELECT s.id
      INTO v_existing_site
      FROM core.sites s
     WHERE s.professional_id = v_professional_id
     LIMIT 1;

    IF v_existing_site IS NULL THEN
        BEGIN
            INSERT INTO core.sites (
                professional_id,
                subdomain,
                theme_id,
                is_published,
                settings
            )
            VALUES (
                v_professional_id,
                v_subdomain,
                NULL,
                false,
                '{}'::jsonb
            );
        EXCEPTION
            WHEN unique_violation THEN
                NULL;
        END;

        IF NOT EXISTS (
            SELECT 1 FROM core.sites s WHERE s.professional_id = v_professional_id
        ) THEN
            BEGIN
                INSERT INTO core.sites (
                    professional_id,
                    subdomain,
                    theme_id,
                    is_published,
                    settings
                )
                VALUES (
                    v_professional_id,
                    v_subdomain || '-' || left(v_professional_id::text, 8),
                    NULL,
                    false,
                    '{}'::jsonb
                );
            EXCEPTION
                WHEN unique_violation THEN
                    NULL;
            END;
        END IF;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM core.sites s WHERE s.professional_id = v_professional_id
    ) THEN
        RAISE EXCEPTION
            'Could not create/find a site for professional % during Natalie promoter migration.',
            v_professional_id;
    END IF;
END $$;

COMMIT;
