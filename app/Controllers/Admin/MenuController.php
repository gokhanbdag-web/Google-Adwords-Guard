<?php
declare(strict_types=1);
namespace App\Controllers\Admin;
use App\Core\Database;
use PDO;
final class MenuController {
 public function index():void{requireAdmin();$menus=Database::connection()->query('SELECT * FROM menus ORDER BY location,sort_order,id')->fetchAll(PDO::FETCH_ASSOC);require BASE_PATH.'/resources/views/admin/menus.php';}
 public function store():void{requireAdmin();$d=$this->validated($_POST);Database::connection()->prepare('INSERT INTO menus(location,label,url,target,sort_order,is_active) VALUES(:location,:label,:url,:target,:sort_order,:active)')->execute($d);$_SESSION['success']='Menü öğesi eklendi.';redirect('/admin/menus');}
 public function update():void{requireAdmin();$d=$this->validated($_POST);$d['id']=(int)($_POST['id']??0);if($d['id']<1){$_SESSION['error']='Geçersiz menü öğesi.';redirect('/admin/menus');}Database::connection()->prepare('UPDATE menus SET location=:location,label=:label,url=:url,target=:target,sort_order=:sort_order,is_active=:active WHERE id=:id')->execute($d);$_SESSION['success']='Menü öğesi güncellendi.';redirect('/admin/menus');}
 public function delete():void{requireAdmin();Database::connection()->prepare('DELETE FROM menus WHERE id=?')->execute([(int)($_POST['id']??0)]);$_SESSION['success']='Menü öğesi silindi.';redirect('/admin/menus');}
 private function validated(array $i):array{
  $location=(string)($i['location']??'header');$label=trim((string)($i['label']??''));$url=trim((string)($i['url']??''));$target=(string)($i['target']??'_self');$order=filter_var($i['sort_order']??0,FILTER_VALIDATE_INT);
  $local=str_starts_with($url,'/')&&!str_starts_with($url,'//');$external=filter_var($url,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true);
  if(!in_array($location,['header','footer'],true)||mb_strlen($label)<1||mb_strlen($label)>120||mb_strlen($url)>500||(!$local&&!$external)||!in_array($target,['_self','_blank'],true)||$order===false){$_SESSION['error']='Menü alanlarını kontrol edin. Site içi bağlantı / ile başlamalıdır.';redirect('/admin/menus');}
  return ['location'=>$location,'label'=>$label,'url'=>$url,'target'=>$target,'sort_order'=>$order,'active'=>isset($i['is_active'])?1:0];
 }
}
