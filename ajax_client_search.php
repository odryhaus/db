<?php

require_once __DIR__ . '/bootstrap.php';
require_login();
ensure_invoice_tables();

header('Content-Type: application/json; charset=utf-8');

if (!can_manage_invoices()) {
    http_response_code(403);
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$type = (string) ($_GET['type'] ?? 'all');
$clientCompanyId = (int) ($_GET['client_company_id'] ?? 0);

if (strlen($q) < 2) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';
$results = [];

function ajax_result(array &$results, string $type, int $id, string $label, string $subtitle = '', ?int $companyId = null, ?int $contactId = null, ?int $legalEntityId = null): void
{
    if ($label === '' || count($results) >= 20) {
        return;
    }

    $results[] = [
        'type' => $type,
        'id' => $id,
        'label' => $label,
        'subtitle' => $subtitle,
        'client_company_id' => $companyId,
        'contact_id' => $contactId,
        'legal_entity_id' => $legalEntityId,
    ];
}

if ($type === 'all' || $type === 'company') {
    $stmt = db()->prepare("
        SELECT id, display_name, keycrm_name, keycrm_title, name, title
        FROM db_client_companies
        WHERE display_name LIKE :q
           OR keycrm_name LIKE :q
           OR keycrm_title LIKE :q
           OR name LIKE :q
           OR title LIKE :q
        ORDER BY COALESCE(display_name, keycrm_title, keycrm_name, title, name) ASC, id DESC
        LIMIT 20
    ");
    $stmt->execute(['q' => $like]);
    foreach ($stmt->fetchAll() as $row) {
        $label = (string) (($row['display_name'] ?? '') ?: (($row['keycrm_title'] ?? '') ?: (($row['keycrm_name'] ?? '') ?: (($row['title'] ?? '') ?: ($row['name'] ?? '')))));
        ajax_result($results, 'company', (int) $row['id'], $label, 'Компанія', (int) $row['id']);
    }
}

if (count($results) < 20 && ($type === 'all' || $type === 'legal_entity')) {
    $sql = "
        SELECT id, client_company_id, legal_name, short_name, edrpou
        FROM db_client_legal_entities
        WHERE (legal_name LIKE :q OR short_name LIKE :q OR edrpou LIKE :q)
    ";
    $params = ['q' => $like];
    if ($clientCompanyId > 0) {
        $sql .= ' AND client_company_id = :client_company_id';
        $params['client_company_id'] = $clientCompanyId;
    }
    $sql .= ' ORDER BY is_default DESC, legal_name ASC, id DESC LIMIT 20';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        ajax_result(
            $results,
            'legal_entity',
            (int) $row['id'],
            (string) $row['legal_name'],
            trim((string) (($row['edrpou'] ?? '') ?: ($row['short_name'] ?? ''))),
            !empty($row['client_company_id']) ? (int) $row['client_company_id'] : null,
            null,
            (int) $row['id']
        );
    }
}

if (count($results) < 20 && ($type === 'all' || $type === 'contact')) {
    $sql = "
        SELECT id, client_company_id, full_name, email, phone
        FROM db_client_contacts
        WHERE (full_name LIKE :q OR email LIKE :q OR phone LIKE :q)
    ";
    $params = ['q' => $like];
    if ($clientCompanyId > 0) {
        $sql .= ' AND client_company_id = :client_company_id';
        $params['client_company_id'] = $clientCompanyId;
    }
    $sql .= ' ORDER BY full_name ASC, id DESC LIMIT 20';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $subtitle = trim((string) (($row['email'] ?? '') . ' ' . ($row['phone'] ?? '')));
        ajax_result(
            $results,
            'contact',
            (int) $row['id'],
            (string) (($row['full_name'] ?? '') ?: (($row['email'] ?? '') ?: ($row['phone'] ?? ''))),
            $subtitle,
            !empty($row['client_company_id']) ? (int) $row['client_company_id'] : null,
            (int) $row['id']
        );
    }
}

echo json_encode(['results' => array_slice($results, 0, 20)], JSON_UNESCAPED_UNICODE);
