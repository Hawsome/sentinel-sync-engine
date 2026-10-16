-- Sync edits and refunds, not just inserts.
-- Fresh installs get all of this from the setup scripts.
--
-- After this runs, every existing source row shares the same updated_at (the
-- moment of the ALTER), so the next sync re-reads all of them once, ordered by
-- id. The upsert makes that harmless.

USE wp_source_db;

ALTER TABLE wp_donations
    ADD COLUMN status     VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER amount_kobo,
    ADD COLUMN updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    ADD INDEX idx_sync_cursor (updated_at, id);

USE accounting_dest_db;

ALTER TABLE synced_donations
    ADD COLUMN donation_status   VARCHAR(20) NOT NULL DEFAULT 'completed' AFTER amount_naira,
    ADD COLUMN source_updated_at TIMESTAMP(6) NULL DEFAULT NULL AFTER donation_status;

ALTER TABLE sync_cursor
    ADD COLUMN last_updated_at TIMESTAMP(6) NOT NULL DEFAULT '2000-01-01 00:00:00.000000' AFTER engine;

-- The old id-only cursor means nothing under the new ordering. Start over.
UPDATE sync_cursor SET last_synced_id = 0 WHERE engine = 'SyncEngine';

ALTER TABLE sync_audit_log
    ADD COLUMN cursor_end_at TIMESTAMP(6) NULL DEFAULT NULL AFTER cursor_end;
