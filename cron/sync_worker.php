<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sync_core.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit;
}

try {
    $result = sync_worker_run_once();
    if ($result === null) {
        echo date('Y-m-d H:i:s') . " no queued jobs\n";
        exit;
    }

    $job = $result['job'] ?? [];
    echo date('Y-m-d H:i:s') . ' job #' . (int) ($job['id'] ?? 0) . ' ' . (string) ($job['job_type'] ?? '') . ' ' . (string) ($result['status'] ?? '') . "\n";
    if (!empty($result['counts']) && is_array($result['counts'])) {
        $counts = $result['counts'];
        echo 'seen=' . (int) ($counts['seen'] ?? 0)
            . ' inserted=' . (int) ($counts['inserted'] ?? 0)
            . ' updated=' . (int) ($counts['updated'] ?? 0)
            . ' unchanged=' . (int) ($counts['unchanged'] ?? 0) . "\n";
    }
    if (!empty($result['error'])) {
        echo 'error=' . (string) $result['error'] . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
