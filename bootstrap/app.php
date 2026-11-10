<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/Autoload.php';
use App\Core\Env;
use App\Core\Router;
use App\Core\Security;
use App\Core\Database;
use App\Controllers\TrackingController;
Env::load(BASE_PATH . '/.env');
$app=require BASE_PATH . '/config/app.php';
date_default_timezone_set($app['timezone']??'UTC');
if(PHP_SAPI!=='cli'&&session_status()!==PHP_SESSION_ACTIVE){
 session_name($app['session']['name']??'adguard_session');
 $sessionPath=parse_url((string)env('APP_URL',''),PHP_URL_PATH)?:'/';$sessionPath=rtrim($sessionPath,'/')?:'/';
 session_set_cookie_params(['lifetime'=>0,'path'=>$sessionPath,'secure'=>(bool)($app['session']['secure']??true),'httponly'=>true,'samesite'=>$app['session']['samesite']??'Lax']);
 ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');session_start();
}
if(PHP_SAPI!=='cli'){
 ini_set('default_charset','UTF-8');mb_internal_encoding('UTF-8');header('Content-Type: text/html; charset=UTF-8');
 header('X-Content-Type-Options: nosniff');header('X-Frame-Options: SAMEORIGIN');header('Referrer-Policy: strict-origin-when-cross-origin');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
$router=new Router();require BASE_PATH.'/routes/web.php';
$router->post('/track',static function():void{(new TrackingController(Database::connection()))->handle();});
if(PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
 if(!in_array($path,['/track','/payments/paytr/callback','/payments/iyzico/callback','/payments/iyzico/webhook'],true)&&!Security::verifyCsrf($_POST['_token']??null)){
  http_response_code(419);exit('Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
 }
}
return $router;
