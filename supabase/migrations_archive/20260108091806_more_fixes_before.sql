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