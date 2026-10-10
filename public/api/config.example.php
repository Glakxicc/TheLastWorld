<?php
// Copier ce fichier en config.php et le remplir. config.php ne doit jamais être commité.

return [
    // URL du webhook Discord qui reçoit les formulaires
    'discord_webhook_url' => '',

    // Sites autorisés à appeler l'API depuis une autre adresse (ex. Live Server).
    // En production le formulaire est sur le même domaine : pas besoin d'y toucher.
    'allowed_origins' => [
        'https://www.thelastworld.fr',
        'https://thelastworld.fr',
        'http://127.0.0.1:5500',
        'http://localhost:5500',
    ],
];
