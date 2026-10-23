<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/lib/ExpressoReservationClient.php';
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') apiReply(405,false,'Use POST multipart.');
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0)
    apiReply(413,false,'Upload excedeu o limite de tamanho do servidor.');
$user=requireOperator($pdo,trim((string)($_POST['api_token']??'')));
$plate=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($_POST['plate']??'')));
$reservation=trim((string)($_POST['reservation']??''));
if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/',$plate))
    apiReply(422,false,'Placa inválida.');
if (!preg_match('/^[0-9A-Za-z\-]{3,40}$/',$reservation))
    apiReply(422,false,'Reserva inválida.');
$keys=['plate_photo','front_photo','right_photo','left_photo','rear_photo','roof_photo'];
foreach ($keys as $key) {
    if (!isset($_FILES[$key]) || ($_FILES[$key]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
        !is_uploaded_file($_FILES[$key]['tmp_name']??'')) {
        apiReply(422,false,'Faltam as seis fotos ou houve falha no envio: '.$key);
    }
}
$created=[];
try {
    $verified=(new ExpressoReservationClient($config['expresso']))->lookup($reservation);
    if ($verified===[]) apiReply(422,false,'Reserva não validada no Expresso.');
    $base=trim((string)$config['upload_dir']);
    if ($base==='' || !is_dir($base) || !is_writable($base))
        throw new RuntimeException('Configure IPLATE_SIM_UPLOAD_DIR fora da pasta pública do servidor.');
    $baseReal=realpath($base);
    if ($baseReal===false) throw new RuntimeException('Diretório de upload inválido.');
    $documentRoot=realpath((string)($_SERVER['DOCUMENT_ROOT']??'')) ?: '';
    if ($documentRoot!=='' &&
        ($baseReal===$documentRoot || str_starts_with($baseReal,$documentRoot.DIRECTORY_SEPARATOR)))
        throw new RuntimeException('Diretório de imagens não pode estar na pasta pública.');
    $sub=date('Y/m');
    $dest=$baseReal.DIRECTORY_SEPARATOR.$sub;
    if (!is_dir($dest) && !mkdir($dest,0700,true) && !is_dir($dest))
        throw new RuntimeException('Não foi possível preparar diretório privado.');
    $saved=[];
    foreach ($keys as $key) {
        $file=$_FILES[$key];
        if ((int)$file['size']<1000 || (int)$file['size']>25*1024*1024)
            throw new RuntimeException('Foto inválida ou maior que 25 MB: '.$key);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $size=getimagesize($file['tmp_name']);
        if ($mime!=='image/jpeg' || $size===false ||
            $size[0]<640 || $size[1]<480 || $size[0]>16000 || $size[1]>16000)
            throw new RuntimeException('Foto JPEG inválida ou resolução inadequada: '.$key);
        $fileName=$key.'_'.bin2hex(random_bytes(16)).'.jpg';
        $absolute=$dest.DIRECTORY_SEPARATOR.$fileName;
        if (!move_uploaded_file($file['tmp_name'],$absolute))
            throw new RuntimeException('Falha ao salvar fotografia: '.$key);
        chmod($absolute,0600);
        $created[]=$absolute;
        $saved[$key]=$sub.'/'.$fileName;
    }
    $pdo->beginTransaction();
    $sql='INSERT INTO iplate_simples_entries
        (user_id,operator_name,reservation_code,guest_name,plate,
         plate_photo_path,front_photo_path,right_photo_path,
         left_photo_path,rear_photo_path,roof_photo_path)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)';
    $stmt=$pdo->prepare($sql);
    $stmt->execute([
        (int)$user['id'],(string)$user['name'],$reservation,
        (string)($verified['guest_name']??''),$plate,
        $saved['plate_photo'],$saved['front_photo'],$saved['right_photo'],
        $saved['left_photo'],$saved['rear_photo'],$saved['roof_photo'],
    ]);
    $id=(int)$pdo->lastInsertId();
    $pdo->commit();
    apiReply(200,true,'Vistoria registrada sem recompressão.',['entry_id'=>$id]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($created as $filename) if (is_file($filename)) unlink($filename);
    error_log('[iPlate Simples] Registro: '.$e->getMessage());
    apiReply(503,false,'Não foi possível gravar a vistoria. Confira as configurações e espaço do servidor.');
}
