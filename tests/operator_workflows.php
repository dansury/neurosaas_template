<?php
declare(strict_types=1);
define('PULL_LIBRARY_ONLY',true);
require dirname(__DIR__).'/pull.php';
require dirname(__DIR__).'/admin/llm_connector/adapter.php';
require dirname(__DIR__).'/admin/llm_connector/llm.php';
$checks=0;function operator_check(bool $ok,string $label):void{global$checks;$checks++;if(!$ok)throw new RuntimeException($label);}
operator_check(LLM::defaults()['order']===['genrouter','openrouter','yandex'],'GenRouter first on fresh adapters');
parse_str((string)parse_url(pat_url('owner/repo',false),PHP_URL_QUERY),$rights);
operator_check($rights['contents']==='write'&&$rights['issues']==='write'&&$rights['pull_requests']==='read','Token prefill covers screenshots, issues and version summaries');
$cfg=['GENROUTER_API_KEY'=>'fixture-genrouter-key','GENROUTER_BASE_URL'=>'https://router.test/v1',
 'GENROUTER_MODEL'=>'fixture/model','LLM_PROVIDER'=>'genrouter','LLM_DEFAULT_MODEL'=>'genrouter:fixture/model',
 'LLM_PROVIDER_PRIORITY'=>'genrouter,openrouter,yandex','LLM_FALLBACK_MODE'=>'manual','LLM_FALLBACK_MODELS'=>'',
 'LLM_VISION_MODEL'=>'genrouter:fixture/model','LLM_MAX_TOKENS'=>500,'OPENROUTER_API_KEY'=>'','YANDEX_API_KEY'=>'',
 'AVAILABLE_MODELS'=>[['id'=>'gr-fixture','provider'=>'genrouter','full_id'=>'fixture/model','vision'=>true,'live'=>true]]];
ToolkitLLM::init($cfg);$chain=ToolkitLLM::candidateChain(true);
operator_check($chain['primary']['provider']==='genrouter'&&$chain['primary']['full_id']==='fixture/model','Qualified GenRouter vision keeps its provider');
$method=new ReflectionMethod(ToolkitLLM::class,'requestFor');$method->setAccessible(true);
$req=$method->invoke(null,$chain['primary'],[['role'=>'user','content'=>'Fixture']],0.0,true);
operator_check($req['url']==='https://router.test/v1/chat/completions'&&in_array('Authorization: Bearer fixture-genrouter-key',$req['headers'],true)&&$req['body']['model']==='fixture/model','Canonical GenRouter request uses its own endpoint/key/model');
$tmp=sys_get_temp_dir().'/operator-'.bin2hex(random_bytes(5));mkdir($tmp);
try{
 foreach(['../escape.php','/absolute.php','C:/windows.php']as$name){$path=$tmp.'/unsafe.zip';$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE);$zip->addFromString($name,'fixture');$zip->close();$zip=new ZipArchive();$zip->open($path);$denied=false;try{validate_archive($zip);}catch(RuntimeException){$denied=true;}$zip->close();operator_check($denied,'Reject unsafe member '.$name);}
 $path=$tmp.'/safe.zip';$zip=new ZipArchive();$zip->open($path,ZipArchive::CREATE);$zip->addFromString('project/index.php','<?php echo "fixture";');$zip->close();$zip=new ZipArchive();$zip->open($path);validate_archive($zip);$zip->close();operator_check(true,'Normal GitHub-style archive accepted');
 $runtime=file_get_contents(dirname(__DIR__).'/admin/deployment/panel.php');
 operator_check(strpos($runtime,"if (PHP_VERSION_ID < 70400)")<strpos($runtime,"NEUROSAAS_ENGINE_20"),'Legacy preflight parses before the engine');
 operator_check(strpos($runtime,'id="embed-tip"')===false&&strpos($runtime,'Ваш сервис под контролем')===false,'Obsolete dashboard UI removed');
 operator_check(in_array('data',ALWAYS_KEEP,true)&&in_array('storage',ALWAYS_KEEP,true)&&in_array('pull-archives',ALWAYS_KEEP,true),'Archive updates preserve operator data and rollback archives');
}finally{cleanup($tmp);}
echo "$checks operator workflow checks passed\n";
