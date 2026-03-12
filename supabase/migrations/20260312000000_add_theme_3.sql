-- Add Theme 3 to the core themes catalogue so it can be selected/published
-- through the existing professional theme endpoints.

INSERT INTO core.themes (
    key,
    name,
    description,
    config,
    is_default
)
VALUES (
    'theme-3',
    'Theme 3',
    'Theme 3 placeholder theme.',
    '{}'::jsonb,
    false
)
ON CONFLICT (key) DO UPDATE
SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    config = EXCLUDED.config,
    is_default = EXCLUDED.is_default,
    updated_at = now();
