<?php

require_once __DIR__ . '/bootstrap.php';

require_login();

$params = $_GET;
$params['status'] = 'debt';
if (!isset($params['month'])) {
    $params['month'] = date('Y-m');
}

redirect_to('/sales.php?' . http_build_query($params));
