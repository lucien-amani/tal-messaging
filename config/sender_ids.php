<?php
declare(strict_types=1);

return [
    'min_length' => 3,
    'max_length' => 11,            // limite habituelle des Sender IDs alphanumériques
    'max_per_org' => 10,           // demandes actives (hors rejetées) par organisation
    'max_pending_per_org' => 5,
    'default_country' => 'CD',
    'purposes' => [
        'transactional' => 'Transactionnel (confirmations, reçus)',
        'otp' => 'Codes de vérification (OTP)',
        'alerts' => 'Alertes et notifications',
        'marketing' => 'Marketing / promotionnel',
        'other' => 'Autre',
    ],
    // Noms protégés : marques d'opérateurs, banques et institutions. Comparés sans espaces ni ponctuation.
    'reserved' => [
        'VODACOM', 'AIRTEL', 'ORANGE', 'AFRICELL', 'MPESA', 'AIRTELMONEY', 'ORANGEMONEY', 'AFRIMONEY', 'TAL', 'TALMESSAGING',
        'GOUVERNEMENT', 'GOVERNMENT', 'POLICE', 'ARMEE', 'BANQUE', 'BANK', 'RAWBANK', 'EQUITY', 'TMB', 'ECOBANK', 'PRESIDENCE',
        'ADMIN', 'INFO', 'SMS', 'TEST', 'ALERT', 'OTP',
    ],
];
