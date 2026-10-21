<?php
require dirname(__DIR__) . '/admin/webapp/WebApp.php';
use NeuroSaaS\WebApp;
use NeuroSaaS\WebAppPush;
$dir = sys_get_temp_dir() . '/webapp-' . bin2hex(random_bytes(6)); mkdir($dir);
register_shutdown_function(static function () use ($dir) { foreach (glob($dir.'/*') ?: [] as $file) @unlink($file); @unlink($dir.'/.htaccess'); @rmdir($dir); });
$checks=0; function app_check($ok,$label) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
WebApp::init($dir.'/fixture.sqlite','mailto:owner@example.test');
foreach (['http://fcm.googleapis.com/a','https://127.0.0.1/a','https://localhost/a','https://fcm.googleapis.com.evil/a','https://user@fcm.googleapis.com/a','https://fcm.googleapis.com:8443/a',"https://fcm.googleapis.com/a\r\nX: bad"] as $bad) app_check(!WebApp::endpointAllowed($bad),'reject unsafe endpoint');
app_check(WebApp::endpointAllowed('https://updates.push.services.mozilla.com/wpush/v2/fixture'),'Mozilla push allowed');
app_check(WebApp::endpointAllowed('https://web.push.apple.com/fixture'),'Apple push allowed');
$keys=WebApp::vapid(); app_check(strlen(base64_decode(strtr($keys['public'],'-_','+/')))===65,'P-256 VAPID public key');
WebApp::init($dir.'/fixture.sqlite'); app_check(WebApp::vapid()===$keys,'VAPID survives reopening storage');
WebApp::setName('Тестовый сервис'); $manifest=WebApp::manifest('/nested/service/');
app_check($manifest['scope']==='/nested/service/' && $manifest['start_url']==='/nested/service/' && $manifest['name']==='Тестовый сервис','manifest respects subfolder and configured name');
$_SERVER['SCRIPT_NAME']='/nested/service/admin/index.php'; app_check(WebApp::basePath()==='/nested/service/','admin base resolves to service root');
$ua=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']); $details=openssl_pkey_get_details($ua);
$point="\x04".str_pad($details['ec']['x'],32,"\0",STR_PAD_LEFT).str_pad($details['ec']['y'],32,"\0",STR_PAD_LEFT); $auth=random_bytes(16);
$b64=static fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$sub=['endpoint'=>'https://updates.push.services.mozilla.com/wpush/v2/fixture','keys'=>['p256dh'=>$b64($point),'auth'=>$b64($auth)]];
WebApp::subscribe($sub); WebApp::subscribe($sub);
$captured=[]; WebAppPush::$http=static function($url,$body,$headers)use(&$captured){$captured=compact('url','body','headers');return 201;};
$result=WebApp::notify('Готово','Задача обработана','./'); app_check($result===['accepted'=>1,'expired'=>0,'failed'=>0],'idempotent subscribe and honest acceptance counts');
$body=$captured['body']; $salt=substr($body,0,16);$asPublic=substr($body,21,ord($body[20]));$encrypted=substr($body,21+strlen($asPublic));
$pem="-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$asPublic),64,"\n")."-----END PUBLIC KEY-----\n";
$ecdh=openssl_pkey_derive(openssl_pkey_get_public($pem),$ua);
$ikm=hash_hkdf('sha256',$ecdh,32,"WebPush: info\0".$point.$asPublic,$auth);
$cek=hash_hkdf('sha256',$ikm,16,"Content-Encoding: aes128gcm\0",$salt);$nonce=hash_hkdf('sha256',$ikm,12,"Content-Encoding: nonce\0",$salt);
$plain=openssl_decrypt(substr($encrypted,0,-16),'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,substr($encrypted,-16));
app_check(substr((string)$plain,-1)==="\x02" && json_decode(substr($plain,0,-1),true)['body']==='Задача обработана','RFC8291 payload decrypts with browser private key');
app_check(strpos($body,'Задача')===false && strpos(implode(' ',$captured['headers']),'vapid t=')!==false,'payload encrypted and VAPID authorization attached');
WebAppPush::$http=static fn()=>410; $result=WebApp::notify('Готово','Тест'); app_check($result['expired']===1,'expired endpoint removed');
app_check(WebApp::notify('Готово','Тест')===['accepted'=>0,'expired'=>0,'failed'=>0],'no subscriptions is not reported as delivery');
try { WebApp::notify('Тест','Текст','https://external.test'); app_check(false,'external URL rejected'); } catch (InvalidArgumentException $e) { app_check(true,'external URL rejected'); }
app_check(is_file($dir.'/.htaccess'),'private data deny rule created');
define('PULL_LIBRARY_ONLY', true); require dirname(__DIR__) . '/pull.php';
app_check(in_array('admin/account/data', ALWAYS_KEEP, true), 'VAPID and subscriptions use mandatory deployment preservation');
echo "OK $checks webapp checks\n";
