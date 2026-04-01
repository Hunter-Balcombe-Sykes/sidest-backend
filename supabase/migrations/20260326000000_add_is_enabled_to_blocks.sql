BEGIN;

ALTER TABLE core.blocks
    ADD COLUMN IF NOT EXISTS is_enabled BOOLEAN NOT NULL DEFAULT false;

-- Backfill: all existing blocks were explicitly created, so enable them
UPDATE core.blocks SET is_enabled = true;

COMMENT ON COLUMN core.blocks.is_enabled IS
    'Whether the section is configured/available. Separate from is_active (publicly visible on site).';

COMMIT;
