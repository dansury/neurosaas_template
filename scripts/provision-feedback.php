<?php
declare(strict_types=1);
/** CLI: supply a token through stdin, choose a private directory outside the checkout. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dir=$argv[1]??'';
if ($dir==='' || (!is_dir($dir) && !mkdir($dir,0700,true))) throw new RuntimeException('Specify a private output directory.');
$resolved=realpath($dir); $root=realpath(dirname(__DIR__));
if ($resolved===false || $root===false || str_starts_with(strtolower($resolved.DIRECTORY_SEPARATOR),strtolower($root.DIRECTORY_SEPARATOR))) throw new RuntimeException('Private keys must be outside the checkout.');
if (is_file($dir.'/feedback.key')) throw new RuntimeException('Refusing to overwrite an existing key.');
$token=trim(stream_get_contents(STDIN));
if ($token==='' || preg_match('/\s/',$token)) throw new RuntimeException('Provide one token through stdin.');
$key=random_bytes(32); $iv=random_bytes(12); $tag='';
$encrypted=openssl_encrypt($token,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'feedback-issue-v1');
if ($encrypted===false) throw new RuntimeException('Encryption failed.');
if (file_put_contents($dir.'/feedback.key',$key,LOCK_EX)!==32 || file_put_contents($dir.'/feedback-credential.enc',base64_encode($iv.$tag.$encrypted),LOCK_EX)===false) throw new RuntimeException('Cannot write credentials.');
chmod($dir.'/feedback.key',0600); chmod($dir.'/feedback-credential.enc',0600);
echo "Encrypted credential and separate decryption key created. Publish only feedback-credential.enc.\n";
