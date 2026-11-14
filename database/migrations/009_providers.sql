-- Module 7 : fournisseurs d'envoi (Africa's Talking, Twilio…) configurés depuis le panneau CEO/CTO.
-- Les identifiants sont chiffrés (AES-256-GCM, clé APP_KEY) et ne sont jamais renvoyés par l'API.
-- Tant qu'aucun fournisseur n'est activé ET configuré, la plateforme reste en simulation.

CREATE TABLE providers (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(30)     NOT NULL,
  name             VARCHAR(60)     NOT NULL,
  enabled          TINYINT(1)      NOT NULL DEFAULT 0,
  priority         INT UNSIGNED    NOT NULL DEFAULT 100,
  credentials      TEXT            NULL,
  settings         TEXT            NULL,
  countries        VARCHAR(255)    NULL,
  webhook_secret   CHAR(40)        NOT NULL,
  last_test_at     DATETIME        NULL,
  last_test_ok     TINYINT(1)      NULL,
  last_test_message VARCHAR(255)   NULL,
  sent_count       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_count     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at  DATETIME        NULL,
  last_failure_at  DATETIME        NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provider_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO providers (code, name, priority, webhook_secret) VALUES ('africastalking', 'Africa''s Talking', 10, SHA1(CONCAT(RAND(), UUID())));
INSERT INTO providers (code, name, priority, webhook_secret) VALUES ('twilio', 'Twilio', 20, SHA1(CONCAT(RAND(), UUID())));

ALTER TABLE messages ADD KEY idx_msg_provider_ref (provider, provider_message_id);
