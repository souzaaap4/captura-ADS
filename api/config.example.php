<?php
/**
 * Copie para config.php (mesma pasta) e preencha o secret real.
 * config.php NUNCA deve ser commitado (está no .gitignore).
 */
return [
    'webhook_url' => 'https://n8n.srv1095468.hstgr.cloud/webhook/lead-ADS',
    'webhook_secret' => 'COLE_AQUI_O_SECRET_REAL',
    'rate_limit_max_requests' => 8,
    'rate_limit_window_seconds' => 60,
];
