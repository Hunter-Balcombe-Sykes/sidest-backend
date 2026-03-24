-- Enterprise + ambassador/promoter validation scenarios
-- Safe to run repeatedly (idempotent via deterministic UUIDs and upserts).

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'core'
          AND table_name = 'enterprises'
    ) THEN
        RAISE NOTICE 'Skipping enterprise seed scenarios: core.enterprises is missing.';
        RETURN;
    END IF;

    -- ============================================================
    -- Enterprises
    -- ============================================================
    INSERT INTO core.enterprises (
        id,
        auth_user_id,
        name,
        handle,
        primary_email,
        phone,
        public_contact_email,
        public_contact_number,
        location_street_address,
        location_city,
        location_state,
        location_postcode,
        location_country,
        enterprise_type,
        status,
        subscription_tier,
        metadata
    )
    VALUES
        ('10000000-0000-0000-0000-000000000001', '30000000-0000-0000-0000-000000000101', 'Atlas Promotions', 'atlas-promotions', 'atlas@example.com', '+61411000001', 'contact@atlas.example.com', '+61411000011', '10 Commerce St', 'Melbourne', 'VIC', '3000', 'Australia', 'promoter', 'active', 'enterprise', jsonb_build_object('seed_case', 'promoter_a')),
        ('10000000-0000-0000-0000-000000000002', '30000000-0000-0000-0000-000000000102', 'Northstar Promoters', 'northstar-promoters', 'northstar@example.com', '+61411000002', 'contact@northstar.example.com', '+61411000012', '22 Agency Ave', 'Sydney', 'NSW', '2000', 'Australia', 'promoter', 'active', 'enterprise', jsonb_build_object('seed_case', 'promoter_b')),
        ('10000000-0000-0000-0000-000000000003', '30000000-0000-0000-0000-000000000103', 'Downtown Salon Group', 'downtown-salon-group', 'downtown@example.com', '+61411000003', 'hello@downtown.example.com', '+61411000013', '8 Collins Ln', 'Melbourne', 'VIC', '3000', 'Australia', 'salon', 'active', 'enterprise', jsonb_build_object('seed_case', 'salon')),
        ('10000000-0000-0000-0000-000000000004', '30000000-0000-0000-0000-000000000104', 'Harbor Barbershop', 'harbor-barbershop', 'harbor@example.com', '+61411000004', 'bookings@harbor.example.com', '+61411000014', '3 Wharf Rd', 'Geelong', 'VIC', '3220', 'Australia', 'barbershop', 'active', 'enterprise', jsonb_build_object('seed_case', 'barbershop'))
    ON CONFLICT (id) DO UPDATE
    SET
        auth_user_id = EXCLUDED.auth_user_id,
        name = EXCLUDED.name,
        handle = EXCLUDED.handle,
        primary_email = EXCLUDED.primary_email,
        phone = EXCLUDED.phone,
        public_contact_email = EXCLUDED.public_contact_email,
        public_contact_number = EXCLUDED.public_contact_number,
        location_street_address = EXCLUDED.location_street_address,
        location_city = EXCLUDED.location_city,
        location_state = EXCLUDED.location_state,
        location_postcode = EXCLUDED.location_postcode,
        location_country = EXCLUDED.location_country,
        enterprise_type = EXCLUDED.enterprise_type,
        status = EXCLUDED.status,
        subscription_tier = EXCLUDED.subscription_tier,
        metadata = EXCLUDED.metadata;

    -- ============================================================
    -- Professionals
    -- ============================================================
    INSERT INTO core.professionals (
        id,
        auth_user_id,
        handle,
        display_name,
        status,
        onboarding_step,
        phone,
        primary_email,
        first_name,
        last_name,
        handle_lc,
        qr_slug,
        professional_type,
        primary_enterprise_id
    )
    VALUES
        -- 1) standalone professional
        ('20000000-0000-0000-0000-000000000001', '30000000-0000-0000-0000-000000000001', 'standalone-pro', 'Standalone Professional', 'active', 0, '+61400000001', 'standalone@example.com', 'Standalone', 'Pro', 'standalone-pro', 'standalone-pro-seed', 'barber', NULL),
        -- 2) professional in one salon
        ('20000000-0000-0000-0000-000000000002', '30000000-0000-0000-0000-000000000002', 'salon-employee', 'Salon Employee', 'active', 0, '+61400000002', 'salon.employee@example.com', 'Salon', 'Employee', 'salon-employee', 'salon-employee-seed', 'barber', '10000000-0000-0000-0000-000000000003'),
        -- 3) professional in multiple enterprises
        ('20000000-0000-0000-0000-000000000003', '30000000-0000-0000-0000-000000000003', 'multi-enterprise-pro', 'Multi Enterprise Professional', 'active', 0, '+61400000003', 'multi.enterprise@example.com', 'Multi', 'Enterprise', 'multi-enterprise-pro', 'multi-enterprise-pro-seed', 'barber', '10000000-0000-0000-0000-000000000004'),
        -- 4) ambassador switching promoters
        ('20000000-0000-0000-0000-000000000004', '30000000-0000-0000-0000-000000000004', 'switching-ambassador', 'Switching Ambassador', 'active', 0, '+61400000004', 'switching.ambassador@example.com', 'Switching', 'Ambassador', 'switching-ambassador', 'switching-ambassador-seed', 'ambassador', '10000000-0000-0000-0000-000000000002'),
        -- 5) ambassador with exclusive promoter + selected products
        ('20000000-0000-0000-0000-000000000005', '30000000-0000-0000-0000-000000000005', 'exclusive-ambassador', 'Exclusive Ambassador', 'active', 0, '+61400000005', 'exclusive.ambassador@example.com', 'Exclusive', 'Ambassador', 'exclusive-ambassador', 'exclusive-ambassador-seed', 'ambassador', '10000000-0000-0000-0000-000000000001')
    ON CONFLICT (id) DO UPDATE
    SET
        auth_user_id = EXCLUDED.auth_user_id,
        handle = EXCLUDED.handle,
        display_name = EXCLUDED.display_name,
        status = EXCLUDED.status,
        onboarding_step = EXCLUDED.onboarding_step,
        phone = EXCLUDED.phone,
        primary_email = EXCLUDED.primary_email,
        first_name = EXCLUDED.first_name,
        last_name = EXCLUDED.last_name,
        handle_lc = EXCLUDED.handle_lc,
        qr_slug = EXCLUDED.qr_slug,
        professional_type = EXCLUDED.professional_type,
        primary_enterprise_id = EXCLUDED.primary_enterprise_id;

    -- ============================================================
    -- Memberships
    -- ============================================================
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
    VALUES
        ('40000000-0000-0000-0000-000000000001', '20000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000003', 'employee', true, now() - interval '180 days', NULL, jsonb_build_object('seed_case', 'one_salon')),
        ('40000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000003', '10000000-0000-0000-0000-000000000003', 'contractor', false, now() - interval '180 days', NULL, jsonb_build_object('seed_case', 'multi_enterprise_secondary')),
        ('40000000-0000-0000-0000-000000000003', '20000000-0000-0000-0000-000000000003', '10000000-0000-0000-0000-000000000004', 'chair_renter', true, now() - interval '180 days', NULL, jsonb_build_object('seed_case', 'multi_enterprise_primary')),
        ('40000000-0000-0000-0000-000000000004', '20000000-0000-0000-0000-000000000004', '10000000-0000-0000-0000-000000000001', 'affiliate', false, now() - interval '180 days', now() - interval '60 days', jsonb_build_object('seed_case', 'ambassador_switch_old_promoter')),
        ('40000000-0000-0000-0000-000000000005', '20000000-0000-0000-0000-000000000004', '10000000-0000-0000-0000-000000000002', 'affiliate', true, now() - interval '60 days', NULL, jsonb_build_object('seed_case', 'ambassador_switch_new_promoter')),
        ('40000000-0000-0000-0000-000000000006', '20000000-0000-0000-0000-000000000005', '10000000-0000-0000-0000-000000000001', 'affiliate', true, now() - interval '45 days', NULL, jsonb_build_object('seed_case', 'exclusive_ambassador'))
    ON CONFLICT (id) DO UPDATE
    SET
        professional_id = EXCLUDED.professional_id,
        enterprise_id = EXCLUDED.enterprise_id,
        relationship_type = EXCLUDED.relationship_type,
        is_primary = EXCLUDED.is_primary,
        starts_at = EXCLUDED.starts_at,
        ends_at = EXCLUDED.ends_at,
        metadata = EXCLUDED.metadata;

    -- ============================================================
    -- Ambassador promoter contracts
    -- ============================================================
    INSERT INTO core.influencer_promoter_contracts (
        id,
        influencer_professional_id,
        promoter_enterprise_id,
        status,
        exclusive,
        starts_at,
        ends_at,
        notes,
        metadata
    )
    VALUES
        -- switching ambassador: old ended contract
        ('50000000-0000-0000-0000-000000000001', '20000000-0000-0000-0000-000000000004', '10000000-0000-0000-0000-000000000001', 'ended', true, now() - interval '180 days', now() - interval '60 days', 'Initial promoter contract ended', jsonb_build_object('seed_case', 'ambassador_switch_old')),
        -- switching ambassador: active exclusive contract with new promoter
        ('50000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000004', '10000000-0000-0000-0000-000000000002', 'active', true, now() - interval '60 days', NULL, 'Current active promoter', jsonb_build_object('seed_case', 'ambassador_switch_new')),
        -- exclusive ambassador with selected products
        ('50000000-0000-0000-0000-000000000003', '20000000-0000-0000-0000-000000000005', '10000000-0000-0000-0000-000000000001', 'active', true, now() - interval '45 days', NULL, 'Exclusive promoter contract', jsonb_build_object('seed_case', 'exclusive_ambassador'))
    ON CONFLICT (id) DO UPDATE
    SET
        influencer_professional_id = EXCLUDED.influencer_professional_id,
        promoter_enterprise_id = EXCLUDED.promoter_enterprise_id,
        status = EXCLUDED.status,
        exclusive = EXCLUDED.exclusive,
        starts_at = EXCLUDED.starts_at,
        ends_at = EXCLUDED.ends_at,
        notes = EXCLUDED.notes,
        metadata = EXCLUDED.metadata;

    -- ============================================================
    -- Promoter Shopify account + brands + products
    -- ============================================================
    INSERT INTO retail.enterprise_shopify_accounts (
        id,
        enterprise_id,
        shop_domain,
        shop_name,
        external_shop_id,
        token_reference,
        is_primary,
        is_active,
        connected_at,
        metadata
    )
    VALUES
        ('60000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000001', 'atlas-promotions.myshopify.com', 'Atlas Promotions Store', 'shop-001', 'vault://shopify/atlas', true, true, now() - interval '45 days', jsonb_build_object('seed_case', 'promoter_a_shop')),
        ('60000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000002', 'northstar-promoters.myshopify.com', 'Northstar Promoters Store', 'shop-002', 'vault://shopify/northstar', true, true, now() - interval '60 days', jsonb_build_object('seed_case', 'promoter_b_shop'))
    ON CONFLICT (id) DO UPDATE
    SET
        enterprise_id = EXCLUDED.enterprise_id,
        shop_domain = EXCLUDED.shop_domain,
        shop_name = EXCLUDED.shop_name,
        external_shop_id = EXCLUDED.external_shop_id,
        token_reference = EXCLUDED.token_reference,
        is_primary = EXCLUDED.is_primary,
        is_active = EXCLUDED.is_active,
        connected_at = EXCLUDED.connected_at,
        metadata = EXCLUDED.metadata;

    INSERT INTO retail.enterprise_brands (
        id,
        enterprise_id,
        name,
        slug,
        description,
        is_active,
        metadata
    )
    VALUES
        ('70000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000001', 'Atlas Signature', 'atlas-signature', 'Promoter A house brand', true, jsonb_build_object('seed_case', 'brand_a')),
        ('70000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000002', 'Northstar Collective', 'northstar-collective', 'Promoter B house brand', true, jsonb_build_object('seed_case', 'brand_b'))
    ON CONFLICT (id) DO UPDATE
    SET
        enterprise_id = EXCLUDED.enterprise_id,
        name = EXCLUDED.name,
        slug = EXCLUDED.slug,
        description = EXCLUDED.description,
        is_active = EXCLUDED.is_active,
        metadata = EXCLUDED.metadata;

    INSERT INTO retail.enterprise_products (
        id,
        enterprise_id,
        shopify_account_id,
        brand_id,
        shopify_product_id,
        title,
        handle,
        product_url,
        image_url,
        price_cents,
        currency_code,
        is_active,
        metadata
    )
    VALUES
        ('80000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001', 'gid://shopify/Product/900001', 'Atlas Matte Clay', 'atlas-matte-clay', 'https://atlas-promotions.myshopify.com/products/atlas-matte-clay', 'https://cdn.example.com/atlas-matte-clay.jpg', 3495, 'AUD', true, jsonb_build_object('seed_case', 'product_a1')),
        ('80000000-0000-0000-0000-000000000002', '10000000-0000-0000-0000-000000000001', '60000000-0000-0000-0000-000000000001', '70000000-0000-0000-0000-000000000001', 'gid://shopify/Product/900002', 'Atlas Sea Salt Spray', 'atlas-sea-salt-spray', 'https://atlas-promotions.myshopify.com/products/atlas-sea-salt-spray', 'https://cdn.example.com/atlas-sea-salt-spray.jpg', 2995, 'AUD', true, jsonb_build_object('seed_case', 'product_a2')),
        ('80000000-0000-0000-0000-000000000003', '10000000-0000-0000-0000-000000000002', '60000000-0000-0000-0000-000000000002', '70000000-0000-0000-0000-000000000002', 'gid://shopify/Product/910001', 'Northstar Texture Dust', 'northstar-texture-dust', 'https://northstar-promoters.myshopify.com/products/northstar-texture-dust', 'https://cdn.example.com/northstar-texture-dust.jpg', 2795, 'AUD', true, jsonb_build_object('seed_case', 'product_b1'))
    ON CONFLICT (id) DO UPDATE
    SET
        enterprise_id = EXCLUDED.enterprise_id,
        shopify_account_id = EXCLUDED.shopify_account_id,
        brand_id = EXCLUDED.brand_id,
        shopify_product_id = EXCLUDED.shopify_product_id,
        title = EXCLUDED.title,
        handle = EXCLUDED.handle,
        product_url = EXCLUDED.product_url,
        image_url = EXCLUDED.image_url,
        price_cents = EXCLUDED.price_cents,
        currency_code = EXCLUDED.currency_code,
        is_active = EXCLUDED.is_active,
        metadata = EXCLUDED.metadata;

    -- ============================================================
    -- Featured selections for exclusive ambassador scenario
    -- ============================================================
    DELETE FROM retail.professional_selections
    WHERE professional_id = '20000000-0000-0000-0000-000000000005';

    INSERT INTO retail.professional_selections (
        id,
        professional_id,
        shopify_product_id,
        enterprise_id,
        sort_order,
        commission_override,
        created_at
    )
    VALUES
        ('90000000-0000-0000-0000-000000000001', '20000000-0000-0000-0000-000000000005', 'gid://shopify/Product/900001', '10000000-0000-0000-0000-000000000001', 0, 12.50, now() - interval '10 days'),
        ('90000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000005', 'gid://shopify/Product/900002', '10000000-0000-0000-0000-000000000001', 1, NULL, now() - interval '10 days')
    ON CONFLICT (id) DO UPDATE
    SET
        professional_id = EXCLUDED.professional_id,
        shopify_product_id = EXCLUDED.shopify_product_id,
        enterprise_id = EXCLUDED.enterprise_id,
        sort_order = EXCLUDED.sort_order,
        commission_override = EXCLUDED.commission_override,
        created_at = EXCLUDED.created_at;
END $$;
