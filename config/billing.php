<?php
declare(strict_types=1);

return [
    'default_currency' => 'USD',
    'currencies' => ['USD' => 'Dollar américain', 'CDF' => 'Franc congolais'],
    'min_topup_micro' => 1000000,          // 1 unité minimum par demande de recharge
    'max_topup_micro' => 100000000000,     // 100 000 unités maximum par demande
    'max_pending_payments' => 5,
    'methods' => ['mobile_money' => 'Mobile Money', 'bank_transfer' => 'Virement bancaire', 'cash' => 'Espèces', 'other' => 'Autre'],
    'sweep_batch' => 1000,
    'issuer' => ['name' => 'TAL Messaging', 'address' => 'République Démocratique du Congo'],
];
