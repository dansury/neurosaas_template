<?php
require dirname(__DIR__) . '/admin/deployment/speech.php';
$checks = 0;
function speech_check($value, $label) { global $checks; ++$checks; if (!$value) throw new RuntimeException($label); }
$raw = 'нужно 2 модели БР-5 напишите test@example.ru сайт https://example.ru/a';
$edited = 'Нужно 2 модели БР-5. Напишите test@example.ru, сайт https://example.ru/a.';
$result = FeedbackSpeech::format($raw, static function ($prompt, $text) use ($raw, $edited) {
    speech_check($text === $raw && strpos($prompt, 'Do not summarize') !== false, 'format original once');
    return $edited;
});
speech_check($result === ['text' => $edited, 'warning' => ''], 'punctuation preserves facts');
foreach (['2' => '3', 'БР-5' => 'БР-6', 'test@example.ru' => 'other@example.ru', '/a' => '/b'] as $from => $to) {
    $bad = str_replace($from, $to, $edited);
    $result = FeedbackSpeech::format($raw, static function () use ($bad) { return $bad; });
    speech_check($result['text'] === $raw && $result['warning'] !== '', 'changed fact rejected: ' . $from);
}
foreach (['', 'Нужно 2 модели.'] as $bad) {
    $result = FeedbackSpeech::format($raw, static function () use ($bad) { return $bad; });
    speech_check($result['text'] === $raw && $result['warning'] !== '', 'empty or missing facts rejected');
}
$result = FeedbackSpeech::format($raw, static function () { throw new RuntimeException('secret upstream response'); });
speech_check($result['text'] === $raw && strpos($result['warning'], 'secret') === false, 'failure keeps transcript without leaking response');
$result = FeedbackSpeech::format('2 плюс 2', static function () { return '2'; });
speech_check($result['text'] === '2 плюс 2', 'duplicate quantities preserved');
echo "OK: $checks feedback speech checks\n";
