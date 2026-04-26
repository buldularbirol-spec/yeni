<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_require_auth();

$db = app_db();
if (!$db || !app_database_ready()) {
    http_response_code(503);
    exit('Sistem hazir degil.');
}

$docId = (int) ($_GET['id'] ?? 0);
$download = (int) ($_GET['download'] ?? 0) === 1;

if ($docId <= 0) {
    http_response_code(400);
    exit('Gecersiz evrak.');
}

$rows = app_fetch_all($db, '
    SELECT id, module_name, related_table, related_id, file_name, file_path, file_type
    FROM docs_files
    WHERE id = :id
    LIMIT 1
', ['id' => $docId]);

if (!$rows) {
    http_response_code(404);
    exit('Evrak bulunamadi.');
}

$doc = $rows[0];
$absolutePath = app_doc_absolute_path((string) $doc['file_path']);
if (!is_file($absolutePath)) {
    http_response_code(404);
    exit('Dosya sistemde bulunamadi.');
}

$mimeType = app_doc_mime_type($absolutePath, (string) ($doc['file_type'] ?? ''));
$actionName = $download ? 'download_doc' : 'view_doc';
app_audit_log('evrak', $actionName, 'docs_files', (int) $doc['id'], ($download ? 'Dosya indirildi: ' : 'Dosya goruntulendi: ') . (string) $doc['file_name']);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . rawurlencode((string) $doc['file_name']) . '"');
readfile($absolutePath);
exit;
