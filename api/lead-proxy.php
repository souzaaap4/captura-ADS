<?php
/**
 * Proxy server-side para o webhook do workflow "lead-Imersão" (n8n).
 *
 * Existe só para manter o secret do webhook (header x-p4-webhook-secret)
 * fora do JavaScript público da landing page. O navegador chama este
 * endpoint sem nenhum segredo; o secret real vive apenas em config.php
 * (gitignored) e é anexado aqui, no servidor, antes de repassar para o n8n.
 *
 * Ver automation/README.md para o desenho completo da automação.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const MAX_BODY_BYTES = 20000; // payload do lead é pequeno; 20 KB já é folgado

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    respond(415, ['ok' => false, 'error' => 'unsupported_content_type']);
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    // config.php não existe neste ambiente (ex.: ainda não foi criado no
    // deploy). Falha de forma explícita em vez de vazar um erro genérico.
    respond(500, ['ok' => false, 'error' => 'proxy_not_configured']);
}
$config = require $configPath;

$rawBody = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
if ($rawBody === false || $rawBody === '') {
    respond(400, ['ok' => false, 'error' => 'empty_body']);
}
if (strlen($rawBody) > MAX_BODY_BYTES) {
    respond(413, ['ok' => false, 'error' => 'payload_too_large']);
}

$decoded = json_decode($rawBody);
if (json_last_error() !== JSON_ERROR_NONE) {
    respond(400, ['ok' => false, 'error' => 'invalid_json']);
}

// --- limite simples de requisições por IP (reduz abuso do endpoint) ---
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitDir = __DIR__ . '/.rate-limit';
if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
}
if (is_dir($rateLimitDir) && is_writable($rateLimitDir)) {
    $maxRequests = $config['rate_limit_max_requests'] ?? 8;
    $windowSeconds = $config['rate_limit_window_seconds'] ?? 60;
    $bucketFile = $rateLimitDir . '/' . hash('sha256', $clientIp) . '.json';

    $fp = fopen($bucketFile, 'c+');
    if ($fp !== false) {
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $bucket = $raw ? json_decode($raw, true) : null;
        $now = time();
        if (!is_array($bucket) || ($now - ($bucket['windowStart'] ?? 0)) >= $windowSeconds) {
            $bucket = ['windowStart' => $now, 'count' => 0];
        }
        $bucket['count']++;
        $exceeded = $bucket['count'] > $maxRequests;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($bucket));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($exceeded) {
            respond(429, ['ok' => false, 'error' => 'rate_limited']);
        }
    }
}

// --- encaminha para o n8n, com o secret anexado só aqui no servidor ---
$ch = curl_init($config['webhook_url']);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $rawBody,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-p4-webhook-secret: ' . $config['webhook_secret'],
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
]);
$n8nResponse = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($n8nResponse === false) {
    respond(502, ['ok' => false, 'error' => 'upstream_unreachable', 'detail' => $curlError]);
}

respond($httpStatus > 0 ? $httpStatus : 200, ['ok' => true]);
