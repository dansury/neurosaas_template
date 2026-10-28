<?php
declare(strict_types=1);

/** Shared server-side transcription. Configuration/transport injection is for offline tests only. */
final class RouterSpeech
{
    public static function models(array $cfg): array
    {
        $models = [];
        foreach ($cfg['AVAILABLE_MODELS'] ?? [] as $row) {
            $provider = $row['provider'] ?? '';
            if (!in_array($provider, ['genrouter', 'openrouter'], true) || empty($row['audio'])) continue;
            if (empty($cfg[strtoupper($provider) . '_API_KEY'])) continue;
            $id = $provider . ':' . ($row['full_id'] ?? '');
            if (substr($id, -1) === ':') continue;
            $models[$id] = ['id'=>$id, 'label'=>(string)($row['label'] ?? $id), 'provider'=>$provider,
                'model'=>(string)$row['full_id'], 'transport'=>($row['audio_transport'] ?? '') === 'transcriptions' ? 'transcriptions' : 'chat'];
        }
        return $models;
    }

    public static function selected(array $cfg, string $requested = ''): array
    {
        $models = self::models($cfg);
        $id = $requested !== '' ? $requested : (string)($cfg['LLM_AUDIO_MODEL'] ?? '');
        if ($id === '' && $models) $id = (string)array_key_first($models);
        if (!isset($models[$id])) throw new RuntimeException('Выберите доступную голосовую модель в настройках GenRouter.');
        return $models[$id];
    }

    public static function transcribe(array $cfg, string $path, string $mime, string $requested = '', ?callable $transport = null): string
    {
        $row = self::selected($cfg, $requested);
        $formats = ['audio/wav'=>'wav', 'audio/x-wav'=>'wav', 'audio/webm'=>'webm', 'audio/ogg'=>'ogg', 'audio/mpeg'=>'mp3', 'audio/mp4'=>'mp4'];
        $mime = trim(explode(';', $mime)[0]);
        if (!isset($formats[$mime]) || !is_file($path) || filesize($path) < 1 || filesize($path) > 10*1024*1024) throw new RuntimeException('Проверьте формат записи и ограничение 10 МБ.');
        $base = $row['provider'] === 'genrouter' ? rtrim((string)$cfg['GENROUTER_BASE_URL'], '/') : 'https://openrouter.ai/api/v1';
        if (!preg_match('~^https://[^\s]+$~D', $base)) throw new RuntimeException('Проверьте HTTPS-адрес провайдера.');
        $headers = ['Authorization: Bearer ' . $cfg[strtoupper($row['provider']) . '_API_KEY']];
        if ($row['transport'] === 'transcriptions') {
            $url = $base . '/audio/transcriptions';
            $payload = ['model'=>$row['model'], 'file'=>new CURLFile($path, $mime, 'voice.' . $formats[$mime]), 'language'=>'ru'];
        } else {
            $url = $base . '/chat/completions';
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode(['model'=>$row['model'], 'temperature'=>0, 'messages'=>[['role'=>'user', 'content'=>[
                ['type'=>'text', 'text'=>'Transcribe Russian speech exactly. Preserve numbers and facts. Return only the transcript.'],
                ['type'=>'input_audio', 'input_audio'=>['format'=>$formats[$mime], 'data'=>base64_encode((string)file_get_contents($path))]]
            ]]]], JSON_THROW_ON_ERROR);
        }
        if ($transport) [$code, $raw] = $transport($url, $headers, $payload);
        else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>90, CURLOPT_FOLLOWLOCATION=>false]);
            $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        }
        $data = json_decode((string)$raw, true);
        $text = $data['text'] ?? $data['choices'][0]['message']['content'] ?? '';
        if ($code !== 200 || !is_string($text) || trim($text) === '' || mb_strlen($text) > 10000) throw new RuntimeException('Распознавание ' . $row['id'] . ' не выполнено (HTTP ' . (int)$code . '). Повторите запись или введите текст.');
        return trim($text);
    }
}
