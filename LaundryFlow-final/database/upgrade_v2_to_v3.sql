-- =====================================================================
-- LaundryFlow: upgrade an EXISTING v2 database to v3 (keeps your data).
--
-- NEW INSTALL? Do NOT use this file. Import laundryflow.sql instead.
--
-- HOW: in phpMyAdmin click the house icon (no database selected),
--      then Import this file. It only touches the `laundryflow` database.
-- =====================================================================
USE laundryflow;

ALTER TABLE users         ADD COLUMN theme VARCHAR(20) NULL AFTER lang;
ALTER TABLE users         ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE customers     ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE orders        ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE payments      ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE discrepancies ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE audit_logs    ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE notifications ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE expenses      ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE cash_closings ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0;

-- The starter accounts and sample customers are demo data
UPDATE users SET is_demo = 1 WHERE username IN ('jessa', 'carlo', 'angela');
UPDATE customers SET is_demo = 1 WHERE id IN (1, 2, 3) AND full_name IN ('Angela Santos', 'Paolo Lim', 'Bea Navarro');

INSERT IGNORE INTO settings (skey, svalue) VALUES ('allow_user_theme', '1'), ('custom_color', '#db2777');

-- Audit log: only demo rows may be deleted
DROP TRIGGER IF EXISTS trg_audit_no_delete;
DELIMITER $$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW BEGIN
  IF OLD.is_demo = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
  END IF;
END$$
DELIMITER ;

-- NOTE: orders/payments loaded with the OLD v2 demo button are not marked as
-- demo. Easiest: if you have no real data yet, drop the database and import
-- laundryflow.sql fresh instead of using this file.
