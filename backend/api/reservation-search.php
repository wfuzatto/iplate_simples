<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/lib/ExpressoReservationClient.php';
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') apiReply(405,false,'Use POST JSON.');
$raw=file_get_contents('php://input',false,null,0,8193);
if ($raw===false || strlen($raw)>8192) apiReply(413,false,'Dados excessivos.');
$input=json_decode($raw,true);
if (!is_array($input) || array_is_list($input)) apiReply(400,false,'JSON inválido.');
$user=requireOperator($pdo,trim((string)($input['api_token']??'')));
$term=trim((string)($input['term']??''));
$mode=(string)($input['mode']??'code');
if (mb_strlen($term)<3 || mb_strlen($term)>100) apiReply(422,false,'Digite de 3 a 100 caracteres.');
if (!in_array($mode,['code','name'],true)) apiReply(422,false,'Modo de busca inválido.');
try {
    if ($mode==='code') {
        if (!preg_match('/^[0-9A-Za-z\-]{3,40}$/',$term))
            apiReply(422,false,'Número de reserva inválido.');
        $item=(new ExpressoReservationClient($config['expresso']))->lookup($term);
        apiReply(200,true,'Consulta concluída.',['items'=>$item===[]?[]:[$item]]);
    }
    // Não existe pesquisa por nome no contrato Expresso. Apenas banco de hotelaria habilitado.
    if (!$config['name_search']) {
        apiReply(422,false,'Busca por nome não disponível na API Expresso. Configure a base de hotelaria complementar.');
    }
    $db=$config['hotelaria_db'];
    if ($db['host']==='' || $db['database']==='' || $db['username']==='')
        apiReply(503,false,'Banco de hotelaria não configurado para busca por nome.');
    $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],$db['port'],$db['database']);
    $hotel=new PDO($dsn,$db['username'],$db['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    // Compatível com o esquema legado informado no iPlate original. Sem acesso a dados
    // Expresso não publicados e sem tentar enumeração de reservas.
    $sql='SELECT r.codigo AS reservation_code,
                 GROUP_CONCAT(DISTINCT h.nome ORDER BY h.nome SEPARATOR ", ") AS guest_name,
                 r.status AS status, r.data_checkin AS checkin_date
          FROM reservas r
          JOIN reserva_hospedes rh ON rh.reserva_id=r.id AND rh.cliente_id=r.cliente_id
          JOIN hospedes h ON h.id=rh.hospede_id
          WHERE h.nome LIKE :name
            AND r.status IN ("confirmada","checkin")
          GROUP BY r.id,r.codigo,r.status,r.data_checkin
          ORDER BY r.data_checkin DESC LIMIT 25';
    $stmt=$hotel->prepare($sql);
    $stmt->execute(['name'=>'%'.$term.'%']);
    $items=[];
    foreach ($stmt->fetchAll() as $row) {
        $items[]=[
            'reservation_code'=>(string)($row['reservation_code']??''),
            'guest_name'=>(string)($row['guest_name']??''),
            'status'=>(string)($row['status']??''),
            'checkin_date'=>(string)($row['checkin_date']??''),
            'source'=>'hotelaria_sql', // candidato; app valida pelo Expresso antes de selecionar
        ];
    }
    apiReply(200,true,'Candidatos locais (confirme no Expresso).',['items'=>$items]);
} catch (Throwable $e) {
    error_log('[iPlate Simples] Busca: '.$e->getMessage());
    apiReply(503,false,'Falha na busca; verifique a integração configurada.');
}
