BEGIN;

ALTER TABLE core.feature_flag_overrides
    DROP CONSTRAINT feature_flag_overrides_scope_set;

ALTER TABLE core.feature_flag_overrides
    ADD CONSTRAINT feature_flag_overrides_scope_xor CHECK (
        (professional_id IS NOT NULL) <> (brand_id IS NOT NULL)
    );

COMMIT;
