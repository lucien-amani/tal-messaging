<?php
declare(strict_types=1);

// Usage : php scripts/worker.php            (un passage)
//         php scripts/worker.php --loop     (tourne en continu, Ctrl+C pour arrêter ; --sleep=3 pour l'intervalle en secondes)
// Envoie les messages en file (Quick Send, programmés, nouvelles tentatives). Sous XAMPP : lancez run-worker.bat.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Modules\Messaging\Services\DispatchService;

$loop = in_array('--loop', $argv, true);
$sleep = 3;
foreach ($argv as $a) {
    if (preg_match('/^--sleep=(\d+)$/', $a, $m)) {
        $sleep = max(1, (int) $m[1]);
    }
}
$provider = null; // le router choisit à chaque message
echo sprintf("[%s] Worker démarré — fournisseur : %s%s\n", date('Y-m-d H:i:s'), (\App\Modules\Providers\Services\ProviderRouter::isSimulation() ? 'simulation (aucun fournisseur configuré)' : implode(', ', \App\Modules\Providers\Services\ProviderRouter::mode()['active'])), $loop ? ' (boucle, Ctrl+C pour arrêter)' : '');
do {
    $r = DispatchService::run($provider);
    if ($r['claimed'] > 0 || $r['recovered'] > 0 || $r['charged'] > 0 || $r['released'] > 0) {
        echo sprintf("[%s] pris : %d · livrés : %d · envoyés : %d · échecs : %d · nouvelles tentatives : %d · annulés : %d · récupérés : %d · débités : %d · libérés : %d\n",
            date('Y-m-d H:i:s'), $r['claimed'], $r['delivered'], $r['sent'], $r['failed'], $r['retried'], $r['cancelled'], $r['recovered'], $r['charged'], $r['released']);
    }
    if ($loop && $r['claimed'] === 0) {
        sleep($sleep);
    }
} while ($loop);
