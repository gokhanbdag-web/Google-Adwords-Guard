<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Database;
final class HomeController {
 public function index():void {
  $pdo=Database::connection();$id=(int)setting('homepage_page_id',0);$page=null;
  if($id>0){$q=$pdo->prepare("SELECT title,content,meta_title,meta_description FROM pages WHERE id=? AND status='published' LIMIT 1");$q->execute([$id]);$page=$q->fetch()?:null;}
  if(!$page){$q=$pdo->query("SELECT title,content,meta_title,meta_description FROM pages WHERE slug='home' AND status='published' LIMIT 1");$page=$q->fetch()?:null;}
  $headerMenus=menu_items('header');$footerMenus=menu_items('footer');$siteName=(string)setting('site_name','AdGuard');
  require BASE_PATH.'/resources/views/frontend/home.php';
 }
}
