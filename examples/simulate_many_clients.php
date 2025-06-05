<?php

const CLIENT_COUNT = 50;
const CLIENT_SCRIPT = __DIR__ . DIRECTORY_SEPARATOR . 'simulate_one_client.php';
const MAX_CONCURRENT = 10;

$processes = [];
$running = 0;
$next = 0;

echo "Lancement de " . CLIENT_COUNT . " clients par groupes de " . MAX_CONCURRENT . "...\n";

while ($next < CLIENT_COUNT || $running > 0) {
    // Démarrer de nouveaux processus si possible
    while ($running < MAX_CONCURRENT && $next < CLIENT_COUNT) {
        $cmd = ['php', CLIENT_SCRIPT, $next];

        $descriptorspec = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (is_resource($process)) {
            $processes[$next] = ['proc' => $process, 'pipes' => $pipes];
            $running++;
        } else {
            echo "Échec du lancement du client $next\n";
        }

        $next++;
    }

    // Vérifie les processus terminés
    foreach ($processes as $id => $data) {
        $status = proc_get_status($data['proc']);
        if (!$status['running']) {
            $output = stream_get_contents($data['pipes'][1]);
            fclose($data['pipes'][1]);

            $error = stream_get_contents($data['pipes'][2]);
            fclose($data['pipes'][2]);

            proc_close($data['proc']);

            //echo "Client $id terminé\n";
            if (trim($output)) {
                echo "  Sortie : $output\n";
            }
            if (trim($error)) {
                echo "  Erreur : $error\n";
            }

            unset($processes[$id]);
            $running--;
        }
    }

    usleep(100_000); // Pause 100ms pour ne pas surcharger le CPU
}

echo "Tous les clients ont terminé.\n";