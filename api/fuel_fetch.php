<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
require_once APP_ROOT . '/inc/fuel.php';
require_role('mod');
try {
    $r = fuel_fetch_reference((string)setting('fuel_source_url'), (string)setting('fuel_keyword'));
    if (!$r['ok']) {
        json_out(['ok' => false, 'error' => $r['error']], 422);
    }
    $r['fetched_at'] = vn_date($r['fetched_at'], true);
    if (isset($r['updated_at'])) {
        $r['updated_at'] = vn_date($r['updated_at'], true);
    }
    json_out($r);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 502);
}
