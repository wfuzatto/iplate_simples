<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/lib/ExpressoReservationClient.php';
require dirname(__DIR__).'/lib/DetailPhotoStorage.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') apiReply(405, false, 'Use POST multipart.');
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    apiReply(413, false, 'Upload excedeu o limite do servidor.');
}
$user = requireOperator($pdo, trim((string)($_POST['api_token'] ?? '')));
$plate = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['plate'] ?? '')));
$reservation = trim((string)($_POST['reservation'] ?? ''));
if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/D', $plate)) {
    apiReply(422, false, 'Placa inválida.');
}
if (!preg_match('/^[0-9A-Za-z\-]{3,40}$/D', $reservation)) {
    apiReply(422, false, 'Reserva inválida.');
}
$required = ['plate_photo', 'front_photo', 'right_photo', 'left_photo', 'rear_photo', 'roof_photo'];
try {
    $details = parseDetailPhotoRequest($_POST, $_FILES);
    $expectedFields = array_merge($required, array_column($details, 'field'));
    foreach (array_keys($_FILES) as $field) {
        if (!in_array($field, $expectedFields, true)) {
            throw new InvalidArgumentException('Campo de fotografia não reconhecido.');
        }
    }
    foreach ($required as $field) {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) ||
            ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Fotografia obrigatória ausente: '.$field);
        }
    }
} catch (InvalidArgumentException $e) {
    apiReply(422, false, $e->getMessage());
}

$createdFiles = [];
try {
    // Somente vincular imagens a uma reserva real validada no Expresso.
    $verified = (new ExpressoReservationClient($config['expresso']))->lookup($reservation);
    if ($verified === []) {
        apiReply(422, false, 'Reserva não validada no Expresso.');
    }
    [$folder, $relative] = privateImageUploadDirectory($config);
    $saved = [];
    foreach ($required as $field) {
        $saved[$field] = storeOriginalJpeg($_FILES[$field], $field,
            $folder, $relative, $createdFiles);
    }
    foreach ($details as &$detail) {
        $detail['path'] = storeOriginalJpeg(
            $_FILES[$detail['field']], $detail['field'], $folder, $relative, $createdFiles);
    }
    unset($detail);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO iplate_simples_entries
        (user_id, operator_name, reservation_code, guest_name, plate,
         plate_photo_path, front_photo_path, right_photo_path,
         left_photo_path, rear_photo_path, roof_photo_path)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int)$user['id'], (string)$user['name'], $reservation,
        (string)($verified['guest_name'] ?? ''), $plate,
        $saved['plate_photo'], $saved['front_photo'], $saved['right_photo'],
        $saved['left_photo'], $saved['rear_photo'], $saved['roof_photo'],
    ]);
    $entryId = (int)$pdo->lastInsertId();
    insertDetailPhotoRows($pdo, $entryId, $details);
    $pdo->commit();
    apiReply(200, true, 'Vistoria e fotografias armazenadas sem recompressão.', [
        'entry_id'=>$entryId,
        'stored_count'=>count($details),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($createdFiles as $filename) {
        if (is_file($filename)) unlink($filename);
    }
    error_log('[iPlate Simples] Registro: '.$e->getMessage());
    apiReply(503, false, 'Falha ao registrar fotos ou vistoria. Verifique a migração e o servidor.');
}
