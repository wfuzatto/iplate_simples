<?php
declare(strict_types=1);

/**
 * Contrato multipart para fotos opcionais de detalhes:
 * detail_count=N, detail_photo_0 .. detail_photo_(N-1),
 * detail_note_0 .. detail_note_(N-1).
 * O limite é por requisição, não por veículo; uploads posteriores são aceitos.
 */
const MAX_DETAIL_PHOTOS_PER_UPLOAD = 24;

function parseDetailPhotoRequest(array $post, array $files): array
{
    $raw = (string)($post['detail_count'] ?? '0');
    if (!preg_match('/^(0|[1-9][0-9]?)$/D', $raw)) {
        throw new InvalidArgumentException('Quantidade de fotos de detalhe inválida.');
    }
    $count = (int)$raw;
    if ($count > MAX_DETAIL_PHOTOS_PER_UPLOAD) {
        throw new InvalidArgumentException(
            'Limite de '.MAX_DETAIL_PHOTOS_PER_UPLOAD.' fotos de detalhe por envio.'
        );
    }
    foreach (array_keys($files) as $name) {
        if (str_starts_with($name, 'detail_photo_')) {
            $index = substr($name, strlen('detail_photo_'));
            if (!ctype_digit($index) || (int)$index >= $count ||
                $name !== 'detail_photo_'.(int)$index) {
                throw new InvalidArgumentException('Foto extra sem índice válido: '.$name);
            }
        }
    }
    foreach (array_keys($post) as $name) {
        if (str_starts_with($name, 'detail_note_')) {
            $index = substr($name, strlen('detail_note_'));
            if (!ctype_digit($index) || (int)$index >= $count ||
                $name !== 'detail_note_'.(int)$index) {
                throw new InvalidArgumentException('Descrição extra sem índice válido.');
            }
        }
    }
    $details = [];
    for ($i = 0; $i < $count; $i++) {
        $field = 'detail_photo_'.$i;
        $file = $files[$field] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !isset($file['tmp_name']) || !is_string($file['tmp_name']) ||
            $file['tmp_name'] === '') {
            throw new InvalidArgumentException('Foto de detalhe obrigatória ausente: '.($i + 1));
        }
        $note = $post['detail_note_'.$i] ?? '';
        if (!is_string($note)) throw new InvalidArgumentException('Descrição inválida.');
        $note = trim($note);
        if (mb_strlen($note, 'UTF-8') > 180) {
            throw new InvalidArgumentException('Descrição excedeu 180 caracteres.');
        }
        $details[] = ['field'=>$field, 'description'=>$note];
    }
    return $details;
}

function privateImageUploadDirectory(array $config): array
{
    $base = trim((string)($config['upload_dir'] ?? ''));
    if ($base === '' || !is_dir($base) || !is_writable($base)) {
        throw new RuntimeException('Configure o diretório privado para armazenar imagens.');
    }
    $baseReal = realpath($base);
    if ($baseReal === false) throw new RuntimeException('Diretório de imagens inválido.');
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    if ($documentRoot !== '' &&
        ($baseReal === $documentRoot || str_starts_with($baseReal,$documentRoot.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('As fotos não devem ficar dentro da pasta pública.');
    }
    $subdir = date('Y/m');
    $folder = $baseReal.DIRECTORY_SEPARATOR.$subdir;
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('Não foi possível criar o diretório de fotos.');
    }
    return [$folder, $subdir];
}

function storeOriginalJpeg(
    array $upload,
    string $field,
    string $folder,
    string $relativeDir,
    array &$createdFiles
): string {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        !is_string($upload['tmp_name'] ?? null) ||
        !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('Falha no recebimento de foto: '.$field);
    }
    $byteSize = (int)($upload['size'] ?? 0);
    if ($byteSize < 1000 || $byteSize > 25 * 1024 * 1024) {
        throw new InvalidArgumentException('Foto vazia ou maior que 25 MB: '.$field);
    }
    $path = $upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $dimensions = getimagesize($path);
    if ($mime !== 'image/jpeg' || $dimensions === false ||
        $dimensions[0] < 640 || $dimensions[1] < 480 ||
        $dimensions[0] > 16000 || $dimensions[1] > 16000) {
        throw new InvalidArgumentException('Foto JPEG ou resolução inválida: '.$field);
    }
    $filename = $field.'_'.bin2hex(random_bytes(16)).'.jpg';
    $absolute = $folder.DIRECTORY_SEPARATOR.$filename;
    if (!move_uploaded_file($path, $absolute)) {
        throw new RuntimeException('Não foi possível salvar foto: '.$field);
    }
    chmod($absolute, 0600);
    $createdFiles[] = $absolute;
    return $relativeDir.'/'.$filename;
}

/**
 * Insere fotografias já gravadas usando a mesma transação da vistoria.
 * Caller é responsável por BEGIN/COMMIT/ROLLBACK.
 */
function insertDetailPhotoRows(PDO $pdo, int $entryId, array $details, int $startSequence = 1): void
{
    if (!$details) return;
    $stmt = $pdo->prepare(
        'INSERT INTO iplate_simples_detail_photos
        (entry_id, sequence_no, photo_path, description)
        VALUES (:entry_id, :sequence_no, :photo_path, :description)'
    );
    foreach ($details as $index => $detail) {
        $stmt->execute([
            ':entry_id'=>$entryId,
            ':sequence_no'=>$startSequence + $index,
            ':photo_path'=>$detail['path'],
            ':description'=>$detail['description'],
        ]);
    }
}
