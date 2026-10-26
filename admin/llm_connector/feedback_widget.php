<?php
function neurosaas_feedback_widget(string $url): void {
    $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<a data-feedback-open href="' . $safe . '" style="position:fixed;right:18px;top:14px;z-index:9999;background:#171717;color:white;padding:8px 12px;border-radius:8px;text-decoration:none;font:14px system-ui">Идеи для доработки</a>';
}
