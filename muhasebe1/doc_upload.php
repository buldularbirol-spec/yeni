<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$user = app_require_auth();
$db = app_db();
$ready = app_database_ready();

if (!$db || !$ready) {
    app_redirect('index.php?module=evrak&ok=' . urlencode('error:Veritabani baglantisi gerekli.'));
}

$moduleName = trim((string) ($_GET['module_name'] ?? $_POST['module_name'] ?? 'evrak')) ?: 'evrak';
$relatedTable = trim((string) ($_GET['related_table'] ?? $_POST['related_table'] ?? ''));
$relatedId = (int) ($_GET['related_id'] ?? $_POST['related_id'] ?? 0);
$returnTo = trim((string) ($_GET['return_to'] ?? $_POST['return_to'] ?? 'index.php?module=evrak')) ?: 'index.php?module=evrak';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
        if (!isset($_FILES['doc_file']) || !is_array($_FILES['doc_file'])) {
            throw new RuntimeException('Yuklenecek dosya bulunamadi.');
        }

        $docId = app_store_document($db, [
            'module_name' => $moduleName,
            'related_table' => $relatedTable !== '' ? $relatedTable : null,
            'related_id' => $relatedId > 0 ? $relatedId : null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ], $_FILES['doc_file']);

        app_audit_log('evrak', 'quick_upload', 'docs_files', $docId, 'Hizli evrak yukleme yapildi.');

        $separator = strpos($returnTo, '?') === false ? '?' : '&';
        app_redirect($returnTo . $separator . 'ok=' . urlencode('doc_uploaded'));
    } catch (Throwable $e) {
        $error = 'Evrak yuklenirken bir hata olustu.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hizli Evrak Yukle | <?= app_h(app_config()['app_name']) ?></title>
    <style>
        :root { --ink:#1f2937; --muted:#6b7280; --line:#eadfce; --accent:#c2410c; --bg:#faf6ee; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; padding:32px 20px; font-family:"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at top left,rgba(250,204,21,.28),transparent 18rem),linear-gradient(135deg,var(--bg),#f3eadc); }
        .shell { width:min(100%,760px); margin:0 auto; }
        .card { background:rgba(255,255,255,.95); border:1px solid var(--line); border-radius:26px; padding:28px; box-shadow:0 24px 60px rgba(124,45,18,.08); }
        h1 { margin:0 0 8px; font-size:1.9rem; }
        p { margin:0 0 18px; color:var(--muted); line-height:1.6; }
        .meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin:18px 0 22px; }
        .meta div { border:1px solid var(--line); background:#fffaf3; border-radius:16px; padding:12px 14px; }
        .meta strong, label { display:block; font-weight:700; margin-bottom:6px; }
        input, textarea { width:100%; border:1px solid var(--line); border-radius:14px; padding:13px 14px; font:inherit; }
        textarea { min-height:110px; resize:vertical; }
        .actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:18px; }
        button, .link-btn { border:0; border-radius:14px; background:var(--accent); color:#fff; padding:13px 18px; font:inherit; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .link-btn { background:#fff1df; color:#7c2d12; border:1px solid #fed7aa; }
        .error { background:#fee2e2; color:#991b1b; border-radius:14px; padding:12px 14px; margin-bottom:16px; font-weight:700; }
        .hint { font-size:.92rem; color:var(--muted); margin-top:14px; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="card">
            <h1>Hizli Evrak Yukle</h1>
            <p><?= app_h($user['full_name']) ?> olarak giris yaptiniz. Belgeyi dogrudan ilgili kayda baglayabilirsiniz.</p>

            <?php if ($error !== ''): ?>
                <div class="error"><?= app_h($error) ?></div>
            <?php endif; ?>

            <div class="meta">
                <div><strong>Modul</strong><?= app_h($moduleName) ?></div>
                <div><strong>Tablo</strong><?= app_h($relatedTable !== '' ? $relatedTable : '-') ?></div>
                <div><strong>Kayit ID</strong><?= app_h((string) ($relatedId > 0 ? $relatedId : '-')) ?></div>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                <input type="hidden" name="module_name" value="<?= app_h($moduleName) ?>">
                <input type="hidden" name="related_table" value="<?= app_h($relatedTable) ?>">
                <input type="hidden" name="related_id" value="<?= app_h((string) $relatedId) ?>">
                <input type="hidden" name="return_to" value="<?= app_h($returnTo) ?>">

                <label for="doc_file">Dosya</label>
                <input id="doc_file" type="file" name="doc_file" required>

                <label for="notes" style="margin-top:16px;">Notlar</label>
                <textarea id="notes" name="notes" placeholder="Belge notu veya aciklamasi"></textarea>

                <div class="actions">
                    <button type="submit">Evragi Yukle</button>
                    <a class="link-btn" href="<?= app_h($returnTo) ?>">Kayda Don</a>
                    <a class="link-btn" href="index.php?module=evrak&filter_module=<?= urlencode($moduleName) ?>&filter_related_table=<?= urlencode($relatedTable) ?>&filter_related_id=<?= (int) $relatedId ?>&prefill_module=<?= urlencode($moduleName) ?>&prefill_related_table=<?= urlencode($relatedTable) ?>&prefill_related_id=<?= (int) $relatedId ?>">Arsivi Ac</a>
                </div>
            </form>

            <div class="hint">Belgeler `/uploads/docs` altina yazilir ve audit log kaydi otomatik olusturulur.</div>
        </div>
    </div>
</body>
</html>
