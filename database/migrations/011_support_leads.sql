-- Tickets support (client <-> équipe TAL) et prospects issus du site public (pipeline commercial).
CREATE TABLE tickets (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36)        NOT NULL,
  organization_id  BIGINT UNSIGNED NOT NULL,
  created_by       BIGINT UNSIGNED NOT NULL,
  subject          VARCHAR(160)    NOT NULL,
  category         ENUM('delivery','billing','sender_id','api','account','other') NOT NULL DEFAULT 'other',
  priority         ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  status           ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  assigned_to      BIGINT UNSIGNED NULL,
  reference        VARCHAR(64)     NULL,
  last_reply_at    DATETIME        NULL,
  last_reply_by    ENUM('client','team') NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_uuid (uuid),
  KEY idx_ticket_org (organization_id, status, id),
  KEY idx_ticket_status (status, priority, id),
  KEY idx_ticket_assignee (assigned_to, status),
  CONSTRAINT fk_ticket_org FOREIGN KEY (organization_id) REFERENCES organizations (id),
  CONSTRAINT fk_ticket_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_replies (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id    BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  is_internal  TINYINT(1)      NOT NULL DEFAULT 0,
  body         TEXT            NOT NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reply_ticket (ticket_id, id),
  CONSTRAINT fk_reply_ticket FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
  CONSTRAINT fk_reply_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid          CHAR(36)        NOT NULL,
  name          VARCHAR(120)    NOT NULL,
  company       VARCHAR(150)    NULL,
  email         VARCHAR(190)    NOT NULL,
  phone         VARCHAR(32)     NULL,
  country       CHAR(2)         NULL,
  monthly_volume VARCHAR(30)    NULL,
  message       VARCHAR(2000)   NULL,
  source        VARCHAR(30)     NOT NULL DEFAULT 'website',
  status        ENUM('new','contacted','qualified','won','lost') NOT NULL DEFAULT 'new',
  owner_id      BIGINT UNSIGNED NULL,
  notes         TEXT            NULL,
  ip            VARCHAR(45)     NULL,
  organization_id BIGINT UNSIGNED NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lead_uuid (uuid),
  KEY idx_lead_status (status, id),
  KEY idx_lead_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
