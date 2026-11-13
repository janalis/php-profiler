<?php

declare(strict_types=1);

require __DIR__ . '/../support/report-oom-scenario.php';
run_report_oom_scenario($argv[1] ?? 'array');
