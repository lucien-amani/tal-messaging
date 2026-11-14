-- Module 6 : Wallet, tarification, paiements (demandes de recharge) et factures. MySQL 8 / MariaDB 10.4+.
-- Tous les montants sont des entiers en « micro-unités » (1 000 000 = 1,00 de la devise) : aucun calcul en virgule flottante.
-- Cycle d'un message : réservé (held) à la mise en file, débité (charged) quand le fournisseur l'accepte, libéré (released) s'il échoue ou est annulé.

CREATE TABLE wallets (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id    BIGINT UNSIGNED NOT NULL,
  currency           CHAR(3)         NOT NULL DEFAULT 'USD',
  balance_micro      BIGINT          NOT NULL DEFAULT 0,
  held_micro         BIGINT          NOT NULL DEFAULT 0,
  low_balance_micro  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_org (organization_id),
  CONSTRAINT fk_wallet_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallet_transactions (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid                 CHAR(36)        NOT NULL,
  organization_id      BIGINT UNSIGNED NOT NULL,
  wallet_id            BIGINT UNSIGNED NOT NULL,
  type                 ENUM('topup','adjustment','charge') NOT NULL,
  amount_micro         BIGINT          NOT NULL,
  balance_after_micro  BIGINT          NOT NULL,
  currency             CHAR(3)         NOT NULL,
  reference_type       VARCHAR(20)     NULL,
  reference_id         BIGINT UNSIGNED NULL,
  message_count        INT UNSIGNED    NOT NULL DEFAULT 0,
  description          VARCHAR(255)    NULL,
  created_by           BIGINT UNSIGNED NULL,
  created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wtx_uuid (uuid),
  KEY idx_wtx_org (organization_id, created_at),
  KEY idx_wtx_ref (reference_type, reference_id),
  CONSTRAINT fk_wtx_wallet FOREIGN KEY (wallet_id) REFERENCES wallets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36)        NOT NULL,
  organization_id  BIGINT UNSIGNED NOT NULL,
  amount_micro     BIGINT UNSIGNED NOT NULL,
  currency         CHAR(3)         NOT NULL,
  method           ENUM('mobile_money','bank_transfer','cash','other') NOT NULL DEFAULT 'mobile_money',
  reference        VARCHAR(80)     NOT NULL,
  note             VARCHAR(255)    NULL,
  status           ENUM('pending','confirmed','rejected','cancelled') NOT NULL DEFAULT 'pending',
  reject_reason    VARCHAR(255)    NULL,
  reviewed_by      BIGINT UNSIGNED NULL,
  reviewed_at      DATETIME        NULL,
  invoice_id       BIGINT UNSIGNED NULL,
  created_by       BIGINT UNSIGNED NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  confirmed_key    VARCHAR(100) GENERATED ALWAYS AS (IF(status = 'confirmed', CONCAT(method, ':', reference), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_uuid (uuid),
  UNIQUE KEY uq_payment_confirmed (confirmed_key),
  KEY idx_payment_org (organization_id, status, created_at),
  CONSTRAINT fk_payment_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoices (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid             CHAR(36)        NOT NULL,
  organization_id  BIGINT UNSIGNED NOT NULL,
  payment_id       BIGINT UNSIGNED NULL,
  number           VARCHAR(20)     NULL,
  customer_name    VARCHAR(190)    NOT NULL,
  description      VARCHAR(255)    NOT NULL,
  amount_micro     BIGINT UNSIGNED NOT NULL,
  currency         CHAR(3)         NOT NULL,
  issued_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoice_uuid (uuid),
  UNIQUE KEY uq_invoice_number (number),
  UNIQUE KEY uq_invoice_payment (payment_id),
  KEY idx_invoice_org (organization_id, issued_at),
  CONSTRAINT fk_invoice_org FOREIGN KEY (organization_id) REFERENCES organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifs par SMS (par segment). organization_id NULL = tarif public ; sinon tarif négocié pour ce client.
-- Le plus précis gagne : client + pays + opérateur, client + pays, client seul, puis public pays + opérateur, pays, défaut.
CREATE TABLE pricing_rules (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id  BIGINT UNSIGNED NULL,
  country          CHAR(2)         NULL,
  operator         VARCHAR(20)     NULL,
  currency         CHAR(3)         NOT NULL DEFAULT 'USD',
  price_micro      BIGINT UNSIGNED NOT NULL,
  active           TINYINT(1)      NOT NULL DEFAULT 1,
  created_by       BIGINT UNSIGNED NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  scope_key        VARCHAR(80) GENERATED ALWAYS AS (CONCAT(COALESCE(organization_id, 0), ':', COALESCE(country, '*'), ':', COALESCE(operator, '*'), ':', currency)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pricing_scope (scope_key),
  KEY idx_pricing_org (organization_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifs de départ MODIFIABLES depuis l'interface (0,04 USD par SMS par défaut, 0,035 USD en RDC).
INSERT INTO pricing_rules (organization_id, country, operator, currency, price_micro) VALUES (NULL, NULL, NULL, 'USD', 40000);
INSERT INTO pricing_rules (organization_id, country, operator, currency, price_micro) VALUES (NULL, 'CD', NULL, 'USD', 35000);

ALTER TABLE message_batches ADD COLUMN cost_micro BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE messages ADD COLUMN cost_micro BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE messages ADD COLUMN billing_status ENUM('none','held','charged','released') NOT NULL DEFAULT 'none';
ALTER TABLE messages ADD KEY idx_msg_billing (billing_status, status);
ALTER TABLE messages ADD KEY idx_msg_org_billing (organization_id, billing_status);
