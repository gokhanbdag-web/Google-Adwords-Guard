<?php
declare(strict_types=1);
namespace App\Controllers\Admin;
use App\Core\Database;
use Throwable;
final class MigrationController {
 public function run():void{
  requireAdmin();$pdo=Database::connection();
  try{
   $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) NOT NULL PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
   $files=glob(BASE_PATH.'/database/migrations/*.sql')?:[];sort($files,SORT_STRING);
   $lookup=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$record=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$applied=[];
   foreach($files as $file){$name=basename($file);$sum=hash_file('sha256',$file);$lookup->execute([$name]);$old=$lookup->fetchColumn();
    if($old!==false){if(!hash_equals((string)$old,$sum))throw new \RuntimeException('Migrasyon dosyası değişmiş: '.$name);continue;}
    $sql=file_get_contents($file);if($sql===false)throw new \RuntimeException('Migrasyon okunamadı: '.$name);
    $sql=preg_replace('/^\s*--.*$/m','',$sql);
    foreach(preg_split('/;\s*(?:\r?\n|$)/',trim($sql))?:[] as $statement){$statement=trim($statement);if($statement!=='')$pdo->exec($statement);}
    $record->execute([$name,$sum]);$applied[]=$name;
   }
   $_SESSION['success']=$applied?'Güncellemeler uygulandı: '.implode(', ',$applied):'Bekleyen veritabanı güncellemesi yok.';
  }catch(Throwable $e){error_log('[AdGuard migrations] '.$e->getMessage());$_SESSION['error']='Güncelleme tamamlanamadı. Önce veritabanı yedeğini kontrol edin ve hosting hata kayıtlarına bakın.';}
  redirect('/admin');
 }
}
