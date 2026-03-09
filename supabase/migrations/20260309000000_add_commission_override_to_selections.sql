-- Add commission_override to retail.professional_selections
-- Allows per-product commission overrides; NULL means use default from config

ALTER TABLE retail.professional_selections
    ADD COLUMN IF NOT EXISTS commission_override numeric(5, 2) DEFAULT NULL;

COMMENT ON COLUMN retail.professional_selections.commission_override 
    IS 'Per-product commission rate override (0-100%). NULL = use default from config.';

-- Keep updated_at current if the table had timestamps (it doesn't in the new schema)
-- But add an index for efficient filtering by commission override
CREATE INDEX IF NOT EXISTS ps_commission_override
    ON retail.professional_selections(professional_id, commission_override);
