<?php
declare(strict_types=1);
// Include before rendering the host application. Reporting is undecided/local-only by default.
require_once __DIR__ . '/toolkit.php';
require_once __DIR__ . '/diagnostics.php';
\NeuroSaaS\Diagnostics::install();
\NeuroSaaS\Toolkit::page();

