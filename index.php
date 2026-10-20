<?php
declare(strict_types=1);
require_once __DIR__ . '/admin/llm_connector/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ваш новый сервис</title>
<style>body{margin:0;background:#f4f7fc;color:#17243b;font:18px/1.6 system-ui,sans-serif}header{display:flex;justify-content:flex-end;padding:24px}a{color:#285cda}main{max-width:760px;margin:7vh auto;padding:30px}h1{font-size:clamp(36px,7vw,64px);line-height:1.1}p{color:#54627b}.card{padding:24px;background:white;border-radius:16px}</style></head>
<body><header><a href="admin/">Админка →</a></header><main><h1>Hello, world!</h1>
<p>Ваш сервис установлен и готов к работе. Эта страница — начало вашего будущего проекта.</p>
<div class="card"><h2>С чего начать</h2><p>Откройте <a href="admin/">админку</a>, создайте пароль и подтвердите email кодом из письма.
Там можно настроить нейросети и почту, включить автообновление, нажать «Обновить» или заказать доработку.</p>
<p>Расскажите AI-агенту, что должен делать сервис: для кого он нужен, какую задачу решает и какой результат вы хотите увидеть.
Передайте агенту ссылку на свой репозиторий. Когда изменения появятся в GitHub, установите их из админки.</p></div>
</main></body></html>
