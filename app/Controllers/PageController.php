<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Database;
final class PageController {
 public function privacy():void{$this->render('privacy');}
 public function show():void{$slug=strtolower(trim((string)($_GET['slug']??'')));if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)){http_response_code(404);exit('Sayfa bulunamadı.');}$this->render($slug);}
 private function render(string $slug):void{
  $q=Database::connection()->prepare("SELECT title,content,meta_title,meta_description FROM pages WHERE slug=? AND status='published' LIMIT 1");$q->execute([$slug]);$page=$q->fetch();
  if(!$page){http_response_code(404);exit('Sayfa bulunamadı.');}
  $headerMenus=menu_items('header');$footerMenus=menu_items('footer');$siteName=(string)setting('site_name','AdGuard');
  require BASE_PATH.'/resources/views/frontend/page.php';
 }
}
