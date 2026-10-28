<?php
declare(strict_types=1);
$tmp=sys_get_temp_dir().'/feedback-auth-'.bin2hex(random_bytes(6));mkdir($tmp,0700,true);
define('PULL_ROOT',$tmp);
require dirname(__DIR__).'/admin/deployment/feedback-auth.php';
$checks=0;
function credentialCheck(bool $value,string $label): void {global $checks;++$checks;if(!$value)throw new RuntimeException($label);}
try {
    $secret='fixture-feedback-secret';$sealed=FeedbackAuth::seal($secret);
    credentialCheck(!str_contains($sealed,$secret) && FeedbackAuth::unseal($sealed)===$secret,'Authenticated encryption roundtrip');
    $raw=base64_decode($sealed);$raw[30]=chr(ord($raw[30])^1);
    credentialCheck(FeedbackAuth::unseal(base64_encode($raw))==='','Tampering rejected');
    credentialCheck(FeedbackAuth::token('legacy-fixture')==='legacy-fixture','Existing operator key preserved');
    file_put_contents($tmp.'/admin/account/data/feedback-credential.enc',$sealed);
    credentialCheck(FeedbackAuth::token('legacy-fixture')===$secret,'Separate encrypted issue credential selected');
    credentialCheck(!str_contains(file_get_contents($tmp.'/admin/account/data/feedback-credential.enc'),$secret),'No plaintext persisted');
} finally {
    foreach(glob($tmp.'/admin/account/data/*')?:[] as $file)unlink($file);
    rmdir($tmp.'/admin/account/data');rmdir($tmp.'/admin/account');rmdir($tmp.'/admin');rmdir($tmp);
}
echo "OK: $checks encrypted feedback credential checks\n";
