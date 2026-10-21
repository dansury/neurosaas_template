<?php
/** Public manifest only: subscriptions and delivery require authenticated admin. */
require_once __DIR__ . '/admin/webapp/WebApp.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
echo json_encode(\NeuroSaaS\WebApp::manifest(\NeuroSaaS\WebApp::basePath()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
