<?php

/**
 * Roteador do servidor HTTP de teste (`php -S 127.0.0.1:<porta> tests/server.php`).
 *
 * Lê o roteiro de respostas de `$BFOCUS_MOCK_DIR/script.json` (uma por requisição, em ordem),
 * grava cada requisição recebida CRUA em `$BFOCUS_MOCK_DIR/requests.jsonl` e responde com a
 * resposta de mesmo índice (sem roteiro: 418). Quem confere o que chegou é o teste.
 */

declare(strict_types=1);

$dir = (string) getenv('BFOCUS_MOCK_DIR');
$logFile = $dir . '/requests.jsonl';

$lines = is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$index = is_array($lines) ? count($lines) : 0;

$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower((string) $name)] = (string) $value;
}

file_put_contents($logFile, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

$script = json_decode((string) file_get_contents($dir . '/script.json'), false, 512, JSON_THROW_ON_ERROR);
$response = is_array($script) ? ($script[$index] ?? null) : null;

header('Content-Type: application/json');
if (!$response instanceof stdClass) {
    http_response_code(418);
    echo json_encode(['error' => 'MOCK_UNEXPECTED_REQUEST']);

    return true;
}

http_response_code((int) $response->status);
echo json_encode($response->body ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

return true;
