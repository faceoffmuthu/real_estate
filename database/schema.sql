-- ------------------------------------------------------------------
-- N Real Estate — base schema (Phase 1 + Phase 2). Later changes: database/migrations/
-- Engine: InnoDB, charset utf8mb4. All timestamps are stored in UTC.
-- Run via `php database/setup.php` (creates the database too) or import
-- into an existing database with phpMyAdmin / the mysql client.
-- ------------------------------------------------------------------

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Role definitions. `level` gives a simple ordering for hierarchy checks.
CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(30)  NOT NULL,
    name        VARCHAR(60)  NOT NULL,
    level       TINYINT UNSIGNED NOT NULL,
    description VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts for every role. `manager_id` links a User to the Admin who owns it.
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id        TINYINT UNSIGNED NOT NULL,
    manager_id     INT UNSIGNED NULL,
    name           VARCHAR(120) NOT NULL,
    username       VARCHAR(50)  NOT NULL,
    email          VARCHAR(190) NOT NULL,
    phone          VARCHAR(20)  NULL,
    password_hash  VARCHAR(255) NOT NULL,
    status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role_id, status),
    KEY idx_users_manager (manager_id),
    KEY idx_users_created_at (created_at),
    CONSTRAINT fk_users_role       FOREIGN KEY (role_id)    REFERENCES roles(id) ON UPDATE CASCADE,
    CONSTRAINT fk_users_manager    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login sessions. Only a SHA-256 hash of the token is stored.
CREATE TABLE IF NOT EXISTS auth_tokens (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    ip_address    VARCHAR(45)  NULL,
    user_agent    VARCHAR(255) NULL,
    expires_at    DATETIME NOT NULL,
    last_used_at  DATETIME NULL,
    revoked_at    DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id, revoked_at),
    KEY idx_auth_tokens_expires (expires_at),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail. entity_type/entity_id point at the affected record
-- (e.g. 'user', 42) so future modules (property, party, approval…) can reuse it.
CREATE TABLE IF NOT EXISTS activity_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(60)  NOT NULL,
    entity_type  VARCHAR(50)  NULL,
    entity_id    INT UNSIGNED NULL,
    description  VARCHAR(255) NOT NULL,
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    metadata     LONGTEXT NULL CHECK (metadata IS NULL OR JSON_VALID(metadata)),
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_user_created (user_id, created_at),
    KEY idx_activity_action_created (action, created_at),
    KEY idx_activity_ip_action (ip_address, action, created_at),
    KEY idx_activity_entity (entity_type, entity_id),
    KEY idx_activity_created (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/value system settings managed by the Super Admin.
CREATE TABLE IF NOT EXISTS settings (
    id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key   VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    value_type    ENUM('string','email','phone','int','bool') NOT NULL DEFAULT 'string',
    label         VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    is_public     TINYINT(1) NOT NULL DEFAULT 0,
    updated_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_settings_key (setting_key),
    CONSTRAINT fk_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference data -----------------------------------------------------

INSERT IGNORE INTO roles (id, slug, name, level, description) VALUES
    (1, 'super_admin', 'Super Admin', 100, 'System-wide access. Manages Admins and system settings.'),
    (2, 'admin',       'Admin',        50, 'Operational manager. Manages their own Users.'),
    (3, 'user',        'User',         10, 'Operational CRM user.');

INSERT IGNORE INTO settings (setting_key, setting_value, value_type, label, description, is_public) VALUES
    ('company_name',         'N Real Estate',         'string', 'Company name',         'Shown in the application header.', 1),
    ('support_email',        NULL,                    'email',  'Support email',        'Contact address shown to users.', 1),
    ('support_phone',        NULL,                    'phone',  'Support phone',        'Contact number shown to users.', 1),
    ('default_country_code', '+91',                   'string', 'Default country code', 'Used for phone / WhatsApp actions in later phases.', 0);

-- ==================================================================
-- Phase 2 — Core real-estate records
-- ==================================================================

-- Master data: property / business types. `field_group` selects which
-- type-specific form section applies, so new types need no code change.
CREATE TABLE IF NOT EXISTS property_types (
    id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug         VARCHAR(50)  NOT NULL,
    name         VARCHAR(80)  NOT NULL,
    field_group  ENUM('rental','residential','commercial','general') NOT NULL DEFAULT 'general',
    description  VARCHAR(255) NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED NULL,
    updated_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_property_types_slug (slug),
    UNIQUE KEY uq_property_types_name (name),
    KEY idx_property_types_active (is_active, sort_order),
    CONSTRAINT fk_property_types_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_property_types_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Master data: business / process stage of a record.
-- (Separate from the Phase 3 approval status on real_estate_records.)
CREATE TABLE IF NOT EXISTS record_process_stages (
    id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40) NOT NULL,
    name        VARCHAR(60) NOT NULL,
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    is_final    TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_process_stages_slug (slug),
    KEY idx_process_stages_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parties (owners, tenants, buyers, brokers …). One party can be linked to
-- many records. Phones are stored normalised (E.164, e.g. +919876543210)
-- for WhatsApp / call actions. Not unique: duplicates are resolved by the
-- user when creating a record, never merged automatically.
CREATE TABLE IF NOT EXISTS parties (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(150) NOT NULL,
    phone       VARCHAR(20)  NOT NULL,
    alt_phone   VARCHAR(20)  NULL,
    email       VARCHAR(190) NULL,
    address     VARCHAR(500) NULL,
    notes       TEXT NULL,
    created_by  INT UNSIGNED NULL,
    updated_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_parties_phone (phone),
    KEY idx_parties_alt_phone (alt_phone),
    KEY idx_parties_name (name),
    KEY idx_parties_created_by (created_by),
    CONSTRAINT fk_parties_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_parties_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The core property / business record.
CREATE TABLE IF NOT EXISTS real_estate_records (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    record_reference  VARCHAR(20) NULL,
    title             VARCHAR(200) NOT NULL,
    property_type_id  SMALLINT UNSIGNED NOT NULL,
    purpose           ENUM('sale','rent','lease','purchase','other') NULL,
    description       TEXT NULL,
    party_id          INT UNSIGNED NOT NULL,

    -- Location
    address_line      VARCHAR(255) NULL,
    locality          VARCHAR(120) NULL,
    city              VARCHAR(100) NULL,
    district          VARCHAR(100) NULL,
    state             VARCHAR(100) NULL,
    pincode           CHAR(6) NULL,

    -- Property details (type-specific columns are NULL when not applicable)
    area_sqft         DECIMAL(12,2) NULL,
    dimensions        VARCHAR(100) NULL,
    floor_details     VARCHAR(100) NULL,
    bedrooms          TINYINT UNSIGNED NULL,
    bathrooms         TINYINT UNSIGNED NULL,
    furnishing        ENUM('unfurnished','semi_furnished','fully_furnished') NULL,
    commercial_usage  VARCHAR(100) NULL,
    rental_amount     DECIMAL(14,2) NULL,
    security_deposit  DECIMAL(14,2) NULL,
    sale_amount       DECIMAL(14,2) NULL,

    -- Process
    process_stage_id  TINYINT UNSIGNED NOT NULL,
    process_notes     TEXT NULL,

    -- Status: record_status = lifecycle (archive = soft delete);
    -- approval_status is reserved for the Phase 3 approval workflow.
    record_status     ENUM('active','archived') NOT NULL DEFAULT 'active',
    approval_status   ENUM('not_submitted','pending','approved','rejected') NOT NULL DEFAULT 'not_submitted',

    created_by        INT UNSIGNED NOT NULL,
    created_by_role   VARCHAR(30) NOT NULL,
    updated_by        INT UNSIGNED NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_records_reference (record_reference),
    KEY idx_records_status_created (record_status, created_at),
    KEY idx_records_owner (created_by, record_status),
    KEY idx_records_type (property_type_id),
    KEY idx_records_stage (process_stage_id),
    KEY idx_records_party (party_id),
    KEY idx_records_city (city),
    KEY idx_records_locality (locality),
    KEY idx_records_approval (approval_status),
    CONSTRAINT fk_records_type       FOREIGN KEY (property_type_id) REFERENCES property_types(id) ON UPDATE CASCADE,
    CONSTRAINT fk_records_party      FOREIGN KEY (party_id)         REFERENCES parties(id) ON UPDATE CASCADE,
    CONSTRAINT fk_records_stage      FOREIGN KEY (process_stage_id) REFERENCES record_process_stages(id) ON UPDATE CASCADE,
    CONSTRAINT fk_records_created_by FOREIGN KEY (created_by)       REFERENCES users(id),
    CONSTRAINT fk_records_updated_by FOREIGN KEY (updated_by)       REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO property_types (id, slug, name, field_group, description, sort_order) VALUES
    (1, 'rental', 'Rental', 'rental', 'Properties offered or taken on rent / lease.', 1),
    (2, 'sale', 'Sale', 'general', 'Properties offered for sale.', 2);

INSERT IGNORE INTO record_process_stages (id, slug, name, sort_order, is_final) VALUES
    (1, 'new',         'New',         1, 0),
    (2, 'contacted',   'Contacted',   2, 0),
    (3, 'discussion',  'Discussion',  3, 0),
    (4, 'site_visit',  'Site Visit',  4, 0),
    (5, 'negotiation', 'Negotiation', 5, 0),
    (6, 'in_progress', 'In Progress', 6, 0),
    (7, 'completed',   'Completed',   7, 1),
    (8, 'closed',      'Closed',      8, 1);
