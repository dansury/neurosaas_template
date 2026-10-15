<?php
declare(strict_types=1);

/** Standalone provider adapter, distilled from dansury/kp-atlant/lib/llm.php. */
final class LLM {
    public const PROVIDERS = ['yandex', 'genrouter', 'openrouter'];
    private array $config;
    private $transport;

    /** Optional transport injection allows offline tests; never configured by HTTP input. */
    public function __construct(array $config, ?callable $transport = null) {
        $this->config = $config;
        $this->transport = $transport;
    }

    public static function defaults(): array {
        return [
            'order' => ['yandex', 'genrouter', 'openrouter'],
            'yandex' => ['enabled' => false, 'key' => '', 'folder' => '', 'model' => 'yandexgpt/latest', 'route' => 'native', 'proxy' => ''],
            'genrouter' => ['enabled' => false, 'key' => '', 'model' => '', 'proxy' => ''],
            'openrouter' => ['enabled' => false, 'key' => '', 'model' => '', 'proxy' => ''],
        ];
    }

    public function ready(string $provider): bool {
        $p = $this->config[$provider] ?? [];
        return in_array($provider, self::PROVIDERS, true) && !empty($p['enabled'])
            && ($p['key'] ?? '') !== '' && ($p['model'] ?? '') !== ''
            && ($provider !== 'yandex' || ($p['folder'] ?? '') !== '');
    }

    public function chatText(string $system, string $user): string {
        return $this->complete($system, $user, false);
    }

    public function chatJson(string $system, string $user): array {
        return json_decode($this->complete($system, $user, true), true, 512, JSON_THROW_ON_ERROR);
    }

    private function complete(string $system, string $user, bool $json, ?string $only = null): string {
        $errors = [];
        $order = $only !== null ? [$only] : ($this->config['order'] ?? self::PROVIDERS);
        foreach (array_unique($order) as $provider) {
            if (!$this->ready($provider)) continue;
            $messages = [
                ['role' => 'system', 'content' => $system . ($json ? "\nReturn only one valid JSON object." : '')],
                ['role' => 'user', 'content' => $user],
            ];
            [$url, $headers, $payload] = $this->request($provider, $messages, $json);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    [$status, $body] = $this->http('POST', $url, $headers, $payload, $provider);
                    if (($status === 429 || $status >= 500) && $attempt === 0) {
                        usleep(200000);
                        continue;
                    }
                    if ($status < 200 || $status >= 300) throw new RuntimeException('HTTP ' . $status);
                    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    $native = $provider === 'yandex' && ($this->config['yandex']['route'] ?? 'native') === 'native';
                    $text = $native ? ($data['result']['alternatives'][0]['message']['text'] ?? '')
                        : ($data['choices'][0]['message']['content'] ?? '');
                    $finish = $native ? ($data['result']['alternatives'][0]['status'] ?? '') : ($data['choices'][0]['finish_reason'] ?? '');
                    if (in_array($finish, ['length', 'ALTERNATIVE_STATUS_TRUNCATED'], true)) throw new RuntimeException('Response truncated');
                    if (!is_string($text) || trim($text) === '') throw new RuntimeException('Empty response');
                    $text = trim($text);
                    if ($json) {
                        $text = trim((string)preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text));
                        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($decoded)) throw new RuntimeException('Expected JSON object or array');
                    }
                    return $text;
                } catch (Throwable $e) {
                    // Do not expose response bodies, credentials, prompts, or proxy URLs.
                    $errors[] = $provider . ': ' . (isset($status) ? 'request/response failed (HTTP ' . $status . ')' : 'connection failed');
                    break;
                }
            }
        }
        throw new RuntimeException($errors ? implode('; ', $errors) : 'No enabled, configured provider.');
    }

    public function test(string $provider): string {
        if (!$this->ready($provider)) throw new RuntimeException('Enable the provider and fill in its key, model and folder if required.');
        return $this->complete('Connection test. Answer briefly.', 'Reply OK.', false, $provider);
    }

    /** Fetch live models rather than shipping a catalog that quickly becomes stale. */
    public function models(string $provider): array {
        if (!in_array($provider, self::PROVIDERS, true)) throw new InvalidArgumentException('Unknown provider');
        $p = $this->config[$provider] ?? [];
        if (($p['key'] ?? '') === '') throw new RuntimeException('Save the API key first.');
        $url = $provider === 'yandex'
            ? 'https://llm.api.cloud.yandex.net/foundationModels/v1/models?folderId=' . rawurlencode($p['folder'] ?? '')
            : $this->base($provider) . '/models';
        [$code, $body] = $this->http('GET', $url, $this->headers($provider), null, $provider);
        if ($code !== 200) throw new RuntimeException('Model catalog: HTTP ' . $code);
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $models = [];
        foreach (($data['data'] ?? $data['models'] ?? []) as $model) {
            $id = (string)($model['id'] ?? $model['modelUri'] ?? '');
            if ($id !== '') $models[] = $id;
        }
        return array_values(array_unique($models));
    }

    private function base(string $provider): string {
        return $provider === 'genrouter' ? 'https://api.genrouter.ru/v1' : 'https://openrouter.ai/api/v1';
    }

    private function headers(string $provider): array {
        return ['Content-Type: application/json', 'Authorization: ' . ($provider === 'yandex' ? 'Api-Key ' : 'Bearer ') . $this->config[$provider]['key']];
    }

    public function request(string $provider, array $messages, bool $json): array {
        $p = $this->config[$provider];
        $model = $p['model'];
        if ($provider === 'yandex') {
            if (strpos($model, 'gpt://') !== 0) {
                if (strpos($model, '/') === false) $model .= '/latest';
                $model = 'gpt://' . $p['folder'] . '/' . $model;
            }
            if (($p['route'] ?? 'native') === 'native') {
                $mapped = array_map(static fn($m) => ['role' => $m['role'], 'text' => $m['content']], $messages);
                $payload = ['modelUri' => $model, 'completionOptions' => ['stream' => false, 'temperature' => 0.3, 'maxTokens' => '2048'], 'messages' => $mapped];
                if ($json) $payload['jsonObject'] = true;
                return ['https://llm.api.cloud.yandex.net/foundationModels/v1/completion', $this->headers($provider), $payload];
            }
            $url = 'https://llm.api.cloud.yandex.net/v1/chat/completions';
        } else {
            $url = $this->base($provider) . '/chat/completions';
        }
        $payload = ['model' => $model, 'messages' => $messages, 'max_tokens' => 2048];
        if ($json) $payload['response_format'] = ['type' => 'json_object'];
        return [$url, $this->headers($provider), $payload];
    }

    private function http(string $method, string $url, array $headers, ?array $payload, string $provider): array {
        if ($this->transport !== null) return ($this->transport)($method, $url, $headers, $payload, $provider);
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required.');
        $ch = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false];
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        if (($this->config[$provider]['proxy'] ?? '') !== '') $options[CURLOPT_PROXY] = $this->config[$provider]['proxy'];
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('Provider connection failed.');
        return [$status, $body];
    }
}
