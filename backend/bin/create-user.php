<?php
declare(strict_types=1);
// Uso CLI: php backend/bin/create-user.php OPERADOR NOME (senha via entrada oculta/STDIN)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc!==3) { fwrite(STDERR,"Uso: php create-user.php USUARIO NOME (senha via STDIN)\n"); exit(2); }
$username=trim($argv[1]);$name=trim($argv[2]);
if (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/',$username) || $name==='')
    { fwrite(STDERR,"Usuário ou nome inválido.\n"); exit(2); }
fwrite(STDERR,"Digite a senha (stdin): ");
$password=trim((string)fgets(STDIN));
if (strlen($password)<12) { fwrite(STDERR,"Use senha com pelo menos 12 caracteres.\n"); exit(2); }
require dirname(__DIR__).'/db.php';
$hash=password_hash($password,PASSWORD_DEFAULT);
$token=bin2hex(random_bytes(32));
$st=$pdo->prepare('INSERT INTO iplate_users (name,username,password_hash,api_token,is_active) VALUES (?,?,?,?,1)
');
$st->execute([$name,$username,$hash,$token]);
fwrite(STDOUT,"Usuário criado. Se já existir, escolha outro nome para não alterar o iPlate original.\n");
