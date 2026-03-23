BEGIN;

-- Keep pending rows accurate before index reshaping.
UPDATE core.brand_affiliate_invites
SET status = 'expired',
    updated_at = now()
WHERE status = 'pending'
  AND expires_at IS NOT NULL
  AND expires_at <= now();

-- Safety dedupe for pending rows scoped by brand+email.
WITH ranked_pending AS (
    SELECT
        id,
        ROW_NUMBER() OVER (
            PARTITION BY brand_professional_id, email_lc
            ORDER BY created_at DESC, id DESC
        ) AS row_num
    FROM core.brand_affiliate_invites
    WHERE status = 'pending'
      AND email_lc IS NOT NULL
)
UPDATE core.brand_affiliate_invites bai
SET status = 'expired',
    updated_at = now()
FROM ranked_pending rp
WHERE bai.id = rp.id
  AND rp.row_num > 1;

DROP INDEX IF EXISTS core.brand_affiliate_invites_pending_email_uq;
DROP INDEX IF EXISTS brand_affiliate_invites_pending_email_uq;

CREATE UNIQUE INDEX IF NOT EXISTS brand_affiliate_invites_pending_brand_email_uq
    ON core.brand_affiliate_invites (brand_professional_id, email_lc)
    WHERE status = 'pending' AND email_lc IS NOT NULL;

COMMIT;
