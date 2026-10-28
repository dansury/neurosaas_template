<?php
declare(strict_types=1);
require dirname(__DIR__).'/admin/deployment/audio.php';
$count=0;
function audioCheck(bool $ok,string $label): void { global $count; ++$count; if (!$ok) throw new RuntimeException($label); }
$cfg=['GENROUTER_API_KEY'=>'fixture-secret','GENROUTER_BASE_URL'=>'https://router.test/v1','OPENROUTER_API_KEY'=>'','LLM_AUDIO_MODEL'=>'genrouter:voice', 'AVAILABLE_MODELS'=>[
    ['provider'=>'genrouter','full_id'=>'voice','audio'=>true,'audio_transport'=>'transcriptions'],
    ['provider'=>'genrouter','full_id'=>'chat-audio','audio'=>true],
    ['provider'=>'genrouter','full_id'=>'text-only','audio'=>false],
    ['provider'=>'openrouter','full_id'=>'other','audio'=>true]
]];
audioCheck(count(RouterSpeech::models($cfg))===2,'Only configured audio models are selectable');
$file=tempnam(sys_get_temp_dir(),'audio-');file_put_contents($file,'fixture');
try {
    $text=RouterSpeech::transcribe($cfg,$file,'audio/wav','genrouter:voice',static function($url,$headers,$payload): array {
        audioCheck($url==='https://router.test/v1/audio/transcriptions' && $payload['model']==='voice' && $payload['file'] instanceof CURLFile,'Multipart transcription transport');
        return [200,'{"text":"Текст 42."}'];
    });
    audioCheck($text==='Текст 42.','Provider transcript returned');
    RouterSpeech::transcribe($cfg,$file,'audio/webm;codecs=opus','genrouter:chat-audio',static function($url,$headers,$payload): array {
        $body=json_decode($payload,true);audioCheck(str_ends_with($url,'/chat/completions') && $body['messages'][0]['content'][1]['input_audio']['format']==='webm','Chat audio transport');
        return [200,'{"choices":[{"message":{"content":"Готово."}}]}'];
    });
    foreach (['genrouter:text-only','openrouter:other','genrouter:unknown'] as $id) {
        $failed=false;try { RouterSpeech::transcribe($cfg,$file,'audio/wav',$id,static function(){throw new RuntimeException('Transport must not be called');}); } catch(RuntimeException $ex) { $failed=!str_contains($ex->getMessage(),'must not'); }
        audioCheck($failed,'Unknown/non-audio/unconfigured model blocked');
    }
    $failed=false;try { RouterSpeech::transcribe($cfg,$file,'audio/wav','genrouter:voice',static fn()=>[401,'{"error":"fixture-secret"}']); } catch(RuntimeException $ex) { $failed=str_contains($ex->getMessage(),'HTTP 401') && !str_contains($ex->getMessage(),'fixture-secret'); }
    audioCheck($failed,'Safe actionable provider failure');
} finally { unlink($file); }
echo "OK: $count shared audio checks\n";
