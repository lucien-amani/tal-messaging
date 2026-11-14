-- Clés API : seule l'empreinte SHA-256 est stockée, la clé complète n'est montrée qu'une fois à la création.
CREATE TABLE api_keys (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id  BIGINT UNSIGNED NOT NULL,
  name             VARCHAR(80)     NOT NULL,
  key_prefix       VARCHAR(16)     NOT NULL,
  key_hash         CHAR(64)        NOT NULL,
  scopes           VARCHAR(60)     NOT NULL DEFAULT 'send,read',
  created_by       BIGINT UNSIGNED NOT NULL,
  last_used_at     DATETIME        NULL,
  last_used_ip     VARCHAR(45)     NULL,
  revoked_at       DATETIME        NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apikey_hash (key_hash),
  KEY idx_apikey_org (organization_id, revoked_at),
  CONSTRAINT fk_apikey_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE api_rate_limits (
  key_id        BIGINT UNSIGNED NOT NULL,
  window_start  INT UNSIGNED    NOT NULL,
  hits          INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (key_id, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
