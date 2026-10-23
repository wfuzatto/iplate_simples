<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/lib/DetailPhotoStorage.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "SKIP: pdo_sqlite indisponível.\n");
    exit(0);
}
function expectStorage(bool $condition,string $what): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$what);
}
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE iplate_simples_entries(id INTEGER PRIMARY KEY, plate TEXT NOT NULL)');
$db->exec('CREATE TABLE iplate_simples_detail_photos(
 id INTEGER PRIMARY KEY, entry_id INTEGER NOT NULL, sequence_no INTEGER NOT NULL,
 photo_path TEXT NOT NULL, description TEXT NOT NULL,
 FOREIGN KEY(entry_id) REFERENCES iplate_simples_entries(id) ON DELETE CASCADE,
 UNIQUE(entry_id,sequence_no))');
$db->exec("INSERT INTO iplate_simples_entries(id,plate) VALUES (7,'ABC1D23'),(8,'XYZ1234')");
$db->beginTransaction();
insertDetailPhotoRows($db,7,[
 ['path'=>'2026/10/photo-a.jpg','description'=>'Amassado na porta'],
 ['path'=>'2026/10/photo-b.jpg','description'=>'Vidro trincado'],
]);
$db->commit();
$db->beginTransaction();
insertDetailPhotoRows($db,7,[
 ['path'=>'2026/10/photo-c.jpg','description'=>'Risco no teto'],
],3);
$db->commit();
$photos=$db->query('SELECT entry_id,sequence_no,photo_path,description
 FROM iplate_simples_detail_photos ORDER BY entry_id,sequence_no')->fetchAll(PDO::FETCH_ASSOC);
expectStorage(count($photos)===3,'Stores initial and appended photos');
expectStorage(array_column($photos,'sequence_no')===[1,2,3],'Order preserved by vehicle');
expectStorage(array_column($photos,'entry_id')===[7,7,7],'All photos belong to selected vehicle');
expectStorage($photos[1]['description']==='Vidro trincado','Stores damage captions');
expectStorage($db->query('SELECT COUNT(*) FROM iplate_simples_detail_photos WHERE entry_id=8')->fetchColumn()===0,
 'Photos never attach to a different vehicle');
$db->beginTransaction();
insertDetailPhotoRows($db,7,[['path'=>'temporary.jpg','description'=>'test']],4);
$db->rollBack();
expectStorage($db->query('SELECT COUNT(*) FROM iplate_simples_detail_photos')->fetchColumn()===3,
 'Rollback prevents orphan metadata');
echo "PASS: vehicle linkage, sequence, append, description and rollback.\n";
