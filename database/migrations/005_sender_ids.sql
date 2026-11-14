-- Module 3 : Sender IDs (demande, validation par TAL, suivi). MySQL 8 / MariaDB 10.4+.
-- Un nom de Sender ID est unique par pays parmi les demandes actives (en attente, approuvées, suspendues) :
-- une demande rejetée ou supprimée libère le nom (colonne générée NULL, voir migration 004).

CREATE TABLE sender_ids (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36)        NOT NULL,
  organization_id  BIGINT UNSIGNED NOT NULL,
  name             VARCHAR(11)     NOT NULL,
  country          CHAR(2)         NOT NULL DEFAULT 'CD',
  purpose          ENUM('transactional','marketing','otp','alerts','other') NOT NULL DEFAULT 'transactional',
  description      VARCHAR(500)    NULL,
  sample_message   VARCHAR(320)    NULL,
  status           ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  status_reason    VARCHAR(255)    NULL,
  reviewed_by      BIGINT UNSIGNED NULL,
  reviewed_at      DATETIME        NULL,
  is_default       TINYINT(1)      NOT NULL DEFAULT 0,
  created_by       BIGINT UNSIGNED NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME        NULL,
  name_unique      VARCHAR(20) GENERATED ALWAYS AS (IF(deleted_at IS NULL AND status <> 'rejected', CONCAT(country, ':', name), NULL)) STORED,
  default_unique   TINYINT(1) GENERATED ALWAYS AS (IF(deleted_at IS NULL AND is_default = 1, 1, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sender_uuid (uuid),
  UNIQUE KEY uq_sender_name (name_unique),
  UNIQUE KEY uq_sender_default (organization_id, default_unique),
  KEY idx_sender_org (organization_id, deleted_at, status),
  KEY idx_sender_status (status, created_at),
  CONSTRAINT fk_sender_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique : demandes, décisions, changements de défaut
CREATE TABLE sender_id_events (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id  BIGINT UNSIGNED NOT NULL,
  sender_id        BIGINT UNSIGNED NOT NULL,
  user_id          BIGINT UNSIGNED NULL,
  action           VARCHAR(30)     NOT NULL,
  note             VARCHAR(255)    NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sevent_sender (sender_id, id),
  KEY idx_sevent_org (organization_id, created_at),
  CONSTRAINT fk_sevent_sender FOREIGN KEY (sender_id) REFERENCES sender_ids (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
