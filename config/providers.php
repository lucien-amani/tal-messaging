<?php
declare(strict_types=1);

/**
 * Catalogue des fournisseurs gérés. Pour chaque champ : secret = jamais réaffiché (« ••• enregistré »).
 * Ajouter un fournisseur = une classe ProviderInterface + une entrée ici + une ligne dans la table providers.
 */
return [
    'catalog' => [
        'africastalking' => [
            'label' => "Africa's Talking", 'docs' => 'https://developers.africastalking.com/docs/sms/sending',
            'description' => 'Fournisseur principal pour l’Afrique : couverture directe des opérateurs, accusés de réception.',
            'fields' => [
                ['key' => 'username', 'label' => 'Nom d’utilisateur (username)', 'secret' => false, 'required' => true, 'placeholder' => 'sandbox pour les essais'],
                ['key' => 'api_key', 'label' => 'Clé API', 'secret' => true, 'required' => true],
                ['key' => 'sandbox', 'label' => 'Mode sandbox (essais, aucun SMS réel)', 'secret' => false, 'required' => false, 'type' => 'bool'],
            ],
            'dlr' => 'Dans Africa’s Talking : SMS → Callback URLs → Delivery Reports, collez l’URL ci-dessous.',
        ],
        'twilio' => [
            'label' => 'Twilio', 'docs' => 'https://www.twilio.com/docs/sms/api/message-resource',
            'description' => 'Fournisseur de secours international.',
            'fields' => [
                ['key' => 'account_sid', 'label' => 'Account SID', 'secret' => false, 'required' => true, 'placeholder' => 'AC…'],
                ['key' => 'auth_token', 'label' => 'Auth Token', 'secret' => true, 'required' => true],
                ['key' => 'from_override', 'label' => 'Numéro ou Messaging Service expéditeur (facultatif)', 'secret' => false, 'required' => false, 'placeholder' => 'Remplace le Sender ID si l’opérateur ne l’accepte pas'],
            ],
            'dlr' => 'Les accusés sont demandés automatiquement (StatusCallback) quand l’application est publiée sur un nom de domaine public.',
        ],
    ],
    'http_timeout' => 15,
    'http_connect_timeout' => 8,
];
