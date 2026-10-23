<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/lib/DetailPhotoStorage.php';

$count=0;
function expect(bool $ok,string $why): void {
    global $count;
    if (!$ok) throw new RuntimeException('FAIL: '.$why);
    $count++;
}
function pretendFiles(int $n): array {
    $files=[];
    for ($i=0;$i<$n;$i++) {
        $files['detail_photo_'.$i]=['error'=>UPLOAD_ERR_OK,'tmp_name'=>'fake-'.$i.'.jpg'];
    }
    return $files;
}
function fails(callable $run,string $expected): void {
    try { $run(); throw new LogicException('Expected exception: '.$expected); }
    catch (InvalidArgumentException $error) {
        expect(str_contains($error->getMessage(),$expected),'Reject '.$expected);
    }
}
expect(parseDetailPhotoRequest([],[])===[],'Old client without detail_count accepted');
expect(parseDetailPhotoRequest(['detail_count'=>'0'],[])===[],'Zero details accepted');
$files=pretendFiles(2);
$input=['detail_count'=>'2','detail_note_0'=>' Amassado na porta ',
    'detail_note_1'=>'Vidro trincado'];
$details=parseDetailPhotoRequest($input,$files);
expect(count($details)===2,'Two detail photos');
expect($details[0]['field']==='detail_photo_0','First indexed file');
expect($details[0]['description']==='Amassado na porta','Trims description');
expect($details[1]['description']==='Vidro trincado','Preserves second description');
expect(count(parseDetailPhotoRequest(['detail_count'=>'2'],$files))===2,'Optional description');
expect(count(parseDetailPhotoRequest(
    ['detail_count'=>(string)MAX_DETAIL_PHOTOS_PER_UPLOAD],
    pretendFiles(MAX_DETAIL_PHOTOS_PER_UPLOAD)
))===MAX_DETAIL_PHOTOS_PER_UPLOAD,'Maximum count allowed');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>(string)(MAX_DETAIL_PHOTOS_PER_UPLOAD+1)],pretendFiles(MAX_DETAIL_PHOTOS_PER_UPLOAD+1)),'Limite');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'2'],pretendFiles(1)),'ausente');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'1'],pretendFiles(2)),'índice');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'0'],pretendFiles(1)),'índice');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'1',
    'detail_note_0'=>str_repeat('X',181)],pretendFiles(1)),'excedeu');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'1',
    'detail_note_0'=>['invalid']],pretendFiles(1)),'Descrição inválida');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'01'],pretendFiles(1)),'inválida');
fails(fn()=>parseDetailPhotoRequest(['detail_count'=>'1',
    'detail_note_1'=>'Extra'],pretendFiles(1)),'índice');
echo "PASS: $count testes da validação de fotos de detalhes.\n";
