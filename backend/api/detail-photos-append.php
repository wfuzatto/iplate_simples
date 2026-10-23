<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/lib/DetailPhotoStorage.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') apiReply(405,false,'Use POST multipart.');
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    apiReply(413,false,'Upload excedeu o limite do servidor.');
}
$user=requireOperator($pdo, trim((string)($_POST['api_token'] ?? '')));
$entryIdRaw=(string)($_POST['entry_id'] ?? '');
if (!preg_match('/^[1-9][0-9]{0,18}$/D',$entryIdRaw)) {
    apiReply(422,false,'Identificador da vistoria inválido.');
}
$entryId=(int)$entryIdRaw;
if ($entryId <= 0) apiReply(422,false,'Identificador inválido.');
try {
    $details=parseDetailPhotoRequest($_POST,$_FILES);
    if (count($details) === 0) {
        apiReply(422,false,'Selecione ao menos uma foto de detalhe.');
    }
    if (count($_FILES) !== count($details)) {
        throw new InvalidArgumentException('Foram enviadas fotos não reconhecidas.');
    }
} catch (InvalidArgumentException $e) {
    apiReply(422,false,$e->getMessage());
}

$createdFiles=[];
try {
    [$folder,$relative]=privateImageUploadDirectory($config);
    foreach ($details as &$detail) {
        $detail['path']=storeOriginalJpeg(
            $_FILES[$detail['field']],$detail['field'],$folder,$relative,$createdFiles
        );
    }
    unset($detail);

    $pdo->beginTransaction();
    // A vistoria deve pertencer ao operador autenticado. A trava serializa
    // anexações simultâneas e impede duplicação de sequence_no no mesmo veículo.
    $stmt=$pdo->prepare(
        'SELECT id FROM iplate_simples_entries
         WHERE id=:entry_id AND user_id=:user_id LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':entry_id'=>$entryId, ':user_id'=>(int)$user['id']]);
    if (!$stmt->fetch()) {
        throw new DomainException('Vistoria não encontrada ou sem permissão.');
    }
    $stmt=$pdo->prepare(
        'SELECT COALESCE(MAX(sequence_no),0)
         FROM iplate_simples_detail_photos WHERE entry_id=:entry_id'
    );
    $stmt->execute([':entry_id'=>$entryId]);
    $nextSequence=(int)$stmt->fetchColumn()+1;
    insertDetailPhotoRows($pdo,$entryId,$details,$nextSequence);
    $pdo->commit();
    apiReply(200,true,'Novas fotos de detalhes anexadas à vistoria.',[
        'entry_id'=>$entryId,
        'stored_count'=>count($details),
        'total_details'=>$nextSequence-1+count($details),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($createdFiles as $filename) if (is_file($filename)) unlink($filename);
    error_log('[iPlate Simples] Acrescentar detalhes: '.$e->getMessage());
    if ($e instanceof DomainException) {
        apiReply(404,false,'Vistoria não encontrada ou sem permissão.');
    }
    apiReply(503,false,'Falha ao anexar novas fotografias. Confira a migração e o servidor.');
}
