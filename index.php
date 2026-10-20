<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/llm_connector/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ваш новый сервис</title>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; background: #fff; color: #17243b; font: 18px/1.6 system-ui, sans-serif; }
header { display: flex; justify-content: flex-end; padding: 24px; }
a { color: #2357cc; text-underline-offset: 3px; }
main { max-width: 820px; margin: 7vh auto; padding: 24px; }
h1 { font-size: clamp(36px, 7vw, 64px); line-height: 1.1; }
p { color: #54627b; }
.card { padding: 24px; border: 1px solid #d9e1ed; border-radius: 16px; }
@media (max-width: 600px) { main { margin: 3vh auto; padding: 20px; } .card { padding: 20px; } }
</style></head>
<body><header><a href="admin/">Админка →</a></header><main><h1>Hello, world!</h1>
<p>Ваш сервис установлен и готов к работе. Эта страница — начало вашего будущего проекта.</p>
<div class="card"><h2>С чего начать</h2><p>Откройте <a href="admin/">админку</a>, создайте пароль и подтвердите email кодом из письма.
Там можно настроить нейросети и почту, включить автообновление, нажать «Обновить» или заказать доработку.</p>
<p>Расскажите AI-агенту, что должен делать сервис: для кого он нужен, какую задачу решает и какой результат вы хотите увидеть.
Передайте агенту ссылку на свой репозиторий. Когда изменения появятся в GitHub, установите их из админки.</p></div>
</main></body></html>
