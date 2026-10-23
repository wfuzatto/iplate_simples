<?php
declare(strict_types=1);
// Credenciais apenas no ambiente do servidor ou config.local.php (ignorado no Git).
$local = is_file(__DIR__.'/config.local.php') ? require __DIR__.'/config.local.php' : [];
if (!is_array($local)) $local = [];
$env = static fn(string $key, string $fallback = ''): string => (string) (getenv($key) ?: $fallback);
return [
    'db' => [
        'host' => $local['db']['host'] ?? $env('IPLATE_DB_HOST', '127.0.0.1'),
        'port' => (int) ($local['db']['port'] ?? $env('IPLATE_DB_PORT', '3306')),
        'database' => $local['db']['database'] ?? $env('IPLATE_DB_NAME', 'iplate'),
        'username' => $local['db']['username'] ?? $env('IPLATE_DB_USER', 'root'),
        'password' => $local['db']['password'] ?? $env('IPLATE_DB_PASSWORD'),
    ],
    'expresso' => [
        'cpf' => $local['expresso']['cpf'] ?? $env('IPLATE_EXPRESSO_CPF'),
        'password' => $local['expresso']['password'] ?? $env('IPLATE_EXPRESSO_PASSWORD'),
        'token_url' => 'https://vale.expresso.app/api/obter_token',
        'reservation_url' => 'https://vale.expresso.app/api/reserva',
        'timeout_seconds' => 25,
    ],
    'hotelaria_db' => [
        'host' => $local['hotelaria_db']['host'] ?? $env('HOTELARIA_DB_HOST'),
        'port' => (int) ($local['hotelaria_db']['port'] ?? $env('HOTELARIA_DB_PORT', '3306')),
        'database' => $local['hotelaria_db']['database'] ?? $env('HOTELARIA_DB_NAME'),
        'username' => $local['hotelaria_db']['username'] ?? $env('HOTELARIA_DB_USER'),
        'password' => $local['hotelaria_db']['password'] ?? $env('HOTELARIA_DB_PASSWORD'),
    ],
    'name_search' => (bool) ($local['name_search'] ?? ($env('IPLATE_SIM_NAME_SEARCH') === '1')),
    'upload_dir' => $local['upload_dir'] ?? $env('IPLATE_SIM_UPLOAD_DIR'),
];
