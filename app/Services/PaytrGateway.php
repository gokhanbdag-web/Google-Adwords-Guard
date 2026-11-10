<?php
declare(strict_types=1);
namespace App\Services;
use RuntimeException;
final class PaytrGateway {
 public function createIframeToken(array $order,array $customer): array {
  $id=(string)env('PAYTR_MERCHANT_ID','');$key=(string)env('PAYTR_MERCHANT_KEY','');$salt=(string)env('PAYTR_MERCHANT_SALT','');
  if($id===''||$key===''||$salt==='')throw new RuntimeException('PayTR mağaza bilgileri yapılandırılmamış.');
  $currency=strtoupper((string)$order['currency']);$currency=$currency==='TRY'?'TL':$currency;
  if(!in_array($currency,['TL','EUR','USD','GBP','RUB'],true))throw new RuntimeException('PayTR para birimi desteklenmiyor.');
  $ip=filter_var($customer['ip']??'',FILTER_VALIDATE_IP);if(!$ip)throw new RuntimeException('Geçerli müşteri IP adresi gerekli.');
  $basket=base64_encode(json_encode([[(string)$order['plan_name'],number_format((float)$order['amount'],2,'.',''),1]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
  $amount=(string)(int)round((float)$order['amount']*100);$no='1';$max='0';$test=(string)env('PAYTR_TEST_MODE','1');
  $hash=$id.$ip.$order['merchant_order_id'].$customer['email'].$amount.$basket.$no.$max.$currency.$test;
  $payload=['merchant_id'=>$id,'user_ip'=>$ip,'merchant_oid'=>$order['merchant_order_id'],'email'=>$customer['email'],'payment_amount'=>$amount,
   'paytr_token'=>base64_encode(hash_hmac('sha256',$hash.$salt,$key,true)),'user_basket'=>$basket,'debug_on'=>'0','no_installment'=>$no,'max_installment'=>$max,
   'user_name'=>mb_substr($customer['name'],0,60),'user_address'=>mb_substr($customer['address'],0,400),'user_phone'=>mb_substr($customer['phone'],0,20),
   'merchant_ok_url'=>rtrim((string)env('APP_URL'),'/').'/payments/return','merchant_fail_url'=>rtrim((string)env('APP_URL'),'/').'/payments/return',
   'timeout_limit'=>'30','currency'=>$currency,'test_mode'=>$test,'lang'=>'tr'];
  $ch=curl_init('https://www.paytr.com/odeme/api/get-token');
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
  $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);$response=is_string($raw)?json_decode($raw,true):null;
  if($status!==200||!is_array($response)||($response['status']??'')!=='success'||empty($response['token']))throw new RuntimeException('PayTR ödeme formu oluşturulamadı: '.(string)(($response['reason']??null)?:($error?:'Sağlayıcı hatası')));
  return ['token'=>(string)$response['token'],'iframe_url'=>'https://www.paytr.com/odeme/guvenli/'.rawurlencode((string)$response['token'])];
 }
 public function verifyCallback(array $data): bool {
  $key=(string)env('PAYTR_MERCHANT_KEY','');$salt=(string)env('PAYTR_MERCHANT_SALT','');$oid=(string)($data['merchant_oid']??'');$received=(string)($data['hash']??'');
  if($key===''||$salt===''||$oid===''||$received==='')return false;
  $expected=base64_encode(hash_hmac('sha256',$oid.$salt.(string)($data['status']??'').(string)($data['total_amount']??''),$key,true));
  return hash_equals($expected,$received);
 }
}