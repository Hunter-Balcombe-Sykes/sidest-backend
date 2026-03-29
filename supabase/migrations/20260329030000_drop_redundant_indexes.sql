-- Remove redundant indexes that add write overhead without query benefit.
-- Each dropped index is fully covered by a remaining index on the same table.

-- ---------------------------------------------------------------------------
-- core.services
-- ---------------------------------------------------------------------------
-- services_prof_active_idx (professional_id, is_active) is fully covered by
-- services_pro_active_sort_covering_idx (professional_id, sort_order)
-- INCLUDE (title, price_cents, is_active) WHERE deleted_at IS NULL AND is_active = true
-- Any query filtering on (professional_id, is_active) is better served by the
-- covering index above.
DROP INDEX IF EXISTS core.services_prof_active_idx;

-- ---------------------------------------------------------------------------
-- core.notifications
-- ---------------------------------------------------------------------------
-- notifications_broadcast_idx (created_at DESC) WHERE professional_id IS NULL
-- is an exact duplicate of notifications_broadcast_active_idx (same definition).
DROP INDEX IF EXISTS core.notifications_broadcast_idx;

-- notifications_target_idx (professional_id, created_at DESC) is redundant with
-- notifications_pro_active_idx (professional_id, created_at DESC)
-- WHERE professional_id IS NOT NULL — the partial index is strictly more efficient
-- (smaller, same coverage for all valid queries since broadcast rows have NULL pro_id).
DROP INDEX IF EXISTS core.notifications_target_idx;

-- ---------------------------------------------------------------------------
-- core.email_subscriptions
-- ---------------------------------------------------------------------------
-- email_subscriptions_lookup_idx (professional_id, list_key, status) is covered
-- by the two partial indexes which have better selectivity:
--   email_subs_global_list_status_idx (list_key, status) WHERE professional_id IS NULL
--   email_subs_pro_list_status_idx (professional_id, list_key, status) WHERE professional_id IS NOT NULL
-- The partial indexes are smaller and selected preferentially by the planner.
DROP INDEX IF EXISTS core.email_subscriptions_lookup_idx;
