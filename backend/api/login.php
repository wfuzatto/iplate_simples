<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') apiReply(405,false,'Método não permitido.');
$username=trim((string)($_POST['username']??''));
$password=(string)($_POST['password']??'');
if ($username===''||$password==='') apiReply(422,false,'Informe usuário e senha.');
try {
    $st=$pdo->prepare('SELECT id,name,username,password_hash,api_token FROM iplate_users WHERE username=:username AND is_active=1 LIMIT 1');
    $st->execute(['username'=>$username]);
    $user=$st->fetch();
    if (!$user || !password_verify($password,(string)$user['password_hash']))
        apiReply(401,false,'Usuário ou senha inválidos.');
    apiReply(200,true,'Login realizado.', ['user'=>[
        'id'=>(int)$user['id'],'name'=>(string)$user['name'],
        'username'=>(string)$user['username'],'api_token'=>(string)$user['api_token']
    ]]);
} catch (Throwable $e) {
    error_log('[iPlate Simples] Login: '.$e->getMessage());
    apiReply(503,false,'Falha na autenticação.');
}
