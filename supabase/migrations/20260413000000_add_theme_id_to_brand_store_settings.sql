-- Add theme_id to brand.brand_store_settings.
-- Stores the brand's selected Hydrogen storefront theme (1–5).
-- Defaults to 1 so all existing brands get theme 1 automatically.

ALTER TABLE brand.brand_store_settings
    ADD COLUMN theme_id SMALLINT NOT NULL DEFAULT 1
        CONSTRAINT brand_store_settings_theme_id_check CHECK (theme_id IN (1, 2, 3, 4, 5));
