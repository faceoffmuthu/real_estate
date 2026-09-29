-- ------------------------------------------------------------------
-- Phase 3 — approval workflow + notifications.
-- Upgrades an existing Phase 2 database without losing data.
-- Applied once by `php database/setup.php` (tracked in schema_migrations).
-- ------------------------------------------------------------------

SET time_zone = '+00:00';

-- 1. Approval status: rename the Phase 2 placeholder `not_submitted` to `draft`.
ALTER TABLE real_estate_records
    MODIFY approval_status ENUM('not_submitted','draft','pending','approved','rejected') NOT NULL DEFAULT 'draft';

-- Phase 2 records predate the workflow: Admin-created records are treated as
-- approved; User-created records enter the review queue as pending.
UPDATE real_estate_records
   SET approval_status = IF(created_by_role = 'user', 'pending', 'approved')
 WHERE approval_status = 'not_submitted';

ALTER TABLE real_estate_records
    MODIFY approval_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft';

-- 2. Review metadata on the record (latest state; full trail in record_approval_history).
ALTER TABLE real_estate_records
    ADD COLUMN submitted_at     DATETIME NULL AFTER approval_status,
    ADD COLUMN reviewed_by      INT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN reviewed_at      DATETIME NULL AFTER reviewed_by,
    ADD COLUMN rejection_reason VARCHAR(1000) NULL AFTER reviewed_at,
    ADD KEY idx_records_approval_submitted (approval_status, submitted_at),
    ADD KEY idx_records_reviewed_by (reviewed_by),
    ADD CONSTRAINT fk_records_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE real_estate_records SET submitted_at = created_at WHERE approval_status = 'pending' AND submitted_at IS NULL;
UPDATE real_estate_records SET reviewed_by = created_by, reviewed_at = created_at
 WHERE approval_status = 'approved' AND created_by_role = 'admin' AND reviewed_at IS NULL;

-- 3. Append-only approval history.
CREATE TABLE IF NOT EXISTS record_approval_history (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_id          INT UNSIGNED NOT NULL,
    action             VARCHAR(30)  NOT NULL,
    performed_by       INT UNSIGNED NULL,
    performed_by_role  VARCHAR(30)  NULL,
    previous_status    VARCHAR(20)  NULL,
    new_status         VARCHAR(20)  NOT NULL,
    reason             VARCHAR(1000) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_history_record (record_id, created_at),
    KEY idx_approval_history_action (action, created_at),
    KEY idx_approval_history_performer (performed_by),
    CONSTRAINT fk_approval_history_record FOREIGN KEY (record_id) REFERENCES real_estate_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_approval_history_user   FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History for records that existed before the workflow.
INSERT INTO record_approval_history (record_id, action, performed_by, performed_by_role, previous_status, new_status, created_at)
SELECT id, IF(approval_status = 'pending', 'submitted', 'created_approved'), created_by, created_by_role, NULL, approval_status, created_at
  FROM real_estate_records;

-- 4. In-app notifications (foundation; email/SMS/WhatsApp come later).
CREATE TABLE IF NOT EXISTS notifications (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    type         VARCHAR(50)  NOT NULL,
    title        VARCHAR(150) NOT NULL,
    message      VARCHAR(500) NOT NULL,
    entity_type  VARCHAR(50)  NULL,
    entity_id    INT UNSIGNED NULL,
    read_at      DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Branding: rename the default company name only if it was never customised.
UPDATE settings SET setting_value = 'N Real Estate'
 WHERE setting_key = 'company_name' AND setting_value = 'Lordminds Real Estate';
