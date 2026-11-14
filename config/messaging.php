<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'max_recipients_per_send' => 5000,   // au-delà : passer par une campagne (module suivant)
    'max_body_chars' => 1600,            // 10 segments GSM-7 concaténés
    'max_schedule_days' => 30,
    'min_schedule_minutes' => 2,         // une programmation doit être au moins dans 2 minutes
    'insert_chunk' => 500,
    'dispatch' => [
        'batch_size' => 100,             // messages pris par tour de worker
        'max_attempts' => 3,
        'retry_delays' => [60, 300, 900], // secondes avant chaque nouvelle tentative
        'stuck_after' => 300,            // un message « sending » depuis plus de 5 min est remis en file
    ],
    // auto = le router utilise les fournisseurs activés et configurés dans le panneau CEO/CTO (simulation s'il n'y en a aucun) ;
    // simulator = coupe-circuit : simulation forcée même si des fournisseurs sont configurés.
    // API publique : requêtes par minute et par clé.
    'api_rate_per_minute' => (int) Env::get('API_RATE_PER_MINUTE', '60'),
    'provider' => Env::get('MESSAGING_PROVIDER', 'auto'),
];
