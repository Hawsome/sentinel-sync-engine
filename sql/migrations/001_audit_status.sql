-- For databases created before audit rows recorded failed runs.
-- Fresh installs get these columns from destination_setup.sql.
USE accounting_dest_db;

ALTER TABLE sync_audit_log
    ADD COLUMN status        VARCHAR(20) NOT NULL DEFAULT 'COMPLETED' AFTER engine,
    ADD COLUMN error_message TEXT NULL AFTER cursor_end;
