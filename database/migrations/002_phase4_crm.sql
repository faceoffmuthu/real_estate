CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_hash (token_hash),
    KEY idx_reset_user (user_id, created_at),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE real_estate_records
    ADD COLUMN assigned_to INT UNSIGNED NULL,
    ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    ADD COLUMN contact_status ENUM('new','attempted','contacted','unreachable','not_interested') NOT NULL DEFAULT 'new',
    ADD COLUMN last_contacted_at DATETIME NULL,
    ADD COLUMN next_action VARCHAR(500) NULL,
    ADD KEY idx_records_assigned (assigned_to),
    ADD KEY idx_records_priority (priority, created_at),
    ADD CONSTRAINT fk_records_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS follow_ups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id INT UNSIGNED NOT NULL,
    assigned_to INT UNSIGNED NOT NULL,
    due_at DATETIME NOT NULL,
    type ENUM('call','whatsapp','meeting','site_visit','email','other') NOT NULL,
    notes VARCHAR(2000) NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    completed_at DATETIME NULL,
    reminded_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_follow_record (record_id, status, due_at),
    KEY idx_follow_due (status, due_at),
    KEY idx_follow_assignee (assigned_to, status, due_at),
    CONSTRAINT fk_follow_record FOREIGN KEY (record_id) REFERENCES real_estate_records(id) ON DELETE CASCADE,
    CONSTRAINT fk_follow_assignee FOREIGN KEY (assigned_to) REFERENCES users(id),
    CONSTRAINT fk_follow_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
