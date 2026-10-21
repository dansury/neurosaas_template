<?php
namespace NeuroSaaS;
require_once __DIR__ . '/WebApp.php';

/** Called only after the existing verified-account gate; no standalone endpoint. */
function webapp_admin(string $csrf, string $email): void
{
    WebApp::init(null, 'mailto:' . $email);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); echo '{"error":"Недействительный CSRF-токен"}'; return; }
        try {
            $action = (string)($_POST['webapp_action'] ?? '');
            $result = ['ok'=>true];
            if ($action === 'key') $result['publicKey'] = WebApp::vapid()['public'];
            elseif ($action === 'subscribe') WebApp::subscribe(json_decode((string)($_POST['subscription'] ?? ''), true) ?: []);
            elseif ($action === 'unsubscribe') WebApp::unsubscribe((string)($_POST['endpoint'] ?? ''));
            elseif ($action === 'test') $result = WebApp::notify('Проверка уведомлений', 'Приложение подключено к этому устройству.', WebApp::basePath(), (string)($_POST['endpoint'] ?? ''));
            elseif ($action === 'name') WebApp::setName((string)($_POST['name'] ?? ''));
            else throw new \InvalidArgumentException('Неизвестное действие.');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
        catch (\Throwable $e) { http_response_code(503); echo json_encode(['error'=>'Модулю нужны PHP OpenSSL, cURL, PDO SQLite и доступ на запись в данные аккаунта.'], JSON_UNESCAPED_UNICODE); }
        return;
    }
    \page_start('Приложение и уведомления'); WebApp::head();
    echo '<section><h2>Приложение и уведомления</h2><p>Установите сервис на телефон или компьютер. Данные сервиса доступны при подключении к интернету.</p>';
    WebApp::installButton();
    echo '<div data-webapp-controls data-csrf="'.htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8').'"><button type="button" data-webapp-subscribe>Включить уведомления на этом устройстве</button> <button type="button" data-webapp-unsubscribe>Отключить уведомления</button> <button type="button" data-webapp-test>Проверить уведомления</button><p data-webapp-push-status role="status"></p></div><p>Разрешение запрашивается только после нажатия. Приложение отправляет уведомления о событиях, которые подключил его разработчик. Проверка показывает приём сервисом доставки, а не гарантирует появление уведомления.</p><p><a href="?">Назад в админку</a></p></section>';
    \page_end();
}
