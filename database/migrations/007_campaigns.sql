-- Module 5 : Campagnes et modèles de message. MySQL 8 / MariaDB 10.4+.
-- Une campagne est un brouillon réutilisable ; au lancement elle crée un envoi (message_batches, source = campaign).

CREATE TABLE campaigns (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  organization_id   BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(120)    NOT NULL,
  sender_id_id      BIGINT UNSIGNED NULL,
  body              VARCHAR(1600)   NOT NULL,
  group_ids         TEXT            NULL,
  segment_ids       TEXT            NULL,
  status            ENUM('draft','launching','scheduled','running','completed','cancelled') NOT NULL DEFAULT 'draft',
  scheduled_at      DATETIME        NULL,
  batch_id          BIGINT UNSIGNED NULL,
  total_recipients  INT UNSIGNED    NOT NULL DEFAULT 0,
  total_segments    INT UNSIGNED    NOT NULL DEFAULT 0,
  launched_at       DATETIME        NULL,
  completed_at      DATETIME        NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_uuid (uuid),
  KEY idx_campaign_org (organization_id, status, created_at),
  KEY idx_campaign_batch (batch_id),
  CONSTRAINT fk_campaign_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_templates (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  organization_id   BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(100)    NOT NULL,
  body              VARCHAR(1600)   NOT NULL,
  created_by        BIGINT UNSIGNED NULL,
  created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        DATETIME        NULL,
  name_unique       VARCHAR(100) GENERATED ALWAYS AS (IF(deleted_at IS NULL, name, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_template_uuid (uuid),
  UNIQUE KEY uq_template_name (organization_id, name_unique),
  CONSTRAINT fk_template_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
