<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
try {
    $db = $config['db'];
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'], $db['port'], $db['database']),
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (Throwable $e) {
    error_log('[iPlate Simples] MySQL: '.$e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>'Banco de dados indisponível.']);
    exit;
}
function apiReply(int $status, bool $success, string $message = '', array $data = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(array_merge(['success'=>$success,'message'=>$message],$data),
        JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function requireOperator(PDO $pdo, string $token): array {
    if (!preg_match('/^[0-9a-f]{64}$/i', $token))
        apiReply(401,false,'Token de operador inválido.');
    $st=$pdo->prepare('SELECT id,name FROM iplate_users WHERE api_token=:token AND is_active=1 LIMIT 1');
    $st->execute(['token'=>$token]);
    $user=$st->fetch();
    if (!$user) apiReply(401,false,'Sessão inválida. Entre novamente.');
    return $user;
}
