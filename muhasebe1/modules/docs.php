<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Evrak ve arsiv modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function docs_post_redirect(string $result): void
{
    app_redirect('index.php?module=evrak&ok=' . urlencode($result));
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';
$prefillModule = trim((string) ($_GET['prefill_module'] ?? ''));
$prefillRelatedTable = trim((string) ($_GET['prefill_related_table'] ?? ''));
$prefillRelatedId = (int) ($_GET['prefill_related_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'upload_doc') {
            $moduleName = trim((string) ($_POST['module_name'] ?? 'evrak')) ?: 'evrak';
            $relatedTable = trim((string) ($_POST['related_table'] ?? '')) ?: null;
            $relatedId = (int) ($_POST['related_id'] ?? 0) ?: null;

            if (!isset($_FILES['doc_file']) || !is_array($_FILES['doc_file'])) {
                throw new RuntimeException('Yuklenecek dosya bulunamadi.');
            }

            $file = $_FILES['doc_file'];
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Dosya yukleme islemi basarisiz oldu.');
            }

            $docId = app_store_document($db, [
                'module_name' => $moduleName,
                'related_table' => $relatedTable,
                'related_id' => $relatedId,
                'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            ], $file);

            app_audit_log('evrak', 'upload_doc', 'docs_files', $docId, 'Yeni evrak yuklendi.');
            docs_post_redirect('upload');
        }

        if ($action === 'delete_doc') {
            $docId = (int) ($_POST['doc_id'] ?? 0);

            if ($docId <= 0) {
                throw new RuntimeException('Gecerli bir evrak secilmedi.');
            }

            $rows = app_fetch_all($db, 'SELECT id, file_name, file_path FROM docs_files WHERE id = :id LIMIT 1', ['id' => $docId]);
            if (!$rows) {
                throw new RuntimeException('Evrak kaydi bulunamadi.');
            }

            $doc = $rows[0];
            $absolutePath = dirname(__DIR__) . str_replace('/muhasebe1', '', (string) $doc['file_path']);
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            $stmt = $db->prepare('DELETE FROM docs_files WHERE id = :id');
            $stmt->execute(['id' => $docId]);

            app_audit_log('evrak', 'delete_doc', 'docs_files', $docId, 'Evrak silindi: ' . (string) $doc['file_name']);
            docs_post_redirect('delete');
        }
    } catch (Throwable $e) {
        $feedback = 'error:Evrak islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$moduleFilter = trim((string) ($_GET['filter_module'] ?? ''));
$relatedTableFilter = trim((string) ($_GET['filter_related_table'] ?? ''));
$relatedIdFilter = (int) ($_GET['filter_related_id'] ?? 0);
$where = [];
$params = [];
if ($moduleFilter !== '') {
    $where[] = 'module_name = :module_name';
    $params['module_name'] = $moduleFilter;
}
if ($relatedTableFilter !== '') {
    $where[] = 'related_table = :related_table';
    $params['related_table'] = $relatedTableFilter;
}
if ($relatedIdFilter > 0) {
    $where[] = 'related_id = :related_id';
    $params['related_id'] = $relatedIdFilter;
}

$whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

$docs = app_fetch_all($db, "
    SELECT id, module_name, related_table, related_id, file_name, file_path, file_type, notes, created_at
    FROM docs_files
    $whereSql
    ORDER BY id DESC
    LIMIT 50
", $params);
$selectedDocId = (int) ($_GET['preview_id'] ?? ($docs[0]['id'] ?? 0));
$selectedDoc = null;
foreach ($docs as $docRow) {
    if ((int) $docRow['id'] === $selectedDocId) {
        $selectedDoc = $docRow;
        break;
    }
}
$selectedDocType = strtolower((string) ($selectedDoc['file_type'] ?? ''));
$selectedDocPreviewable = in_array($selectedDocType, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true);

$summary = [
    'Toplam Evrak' => app_table_count($db, 'docs_files'),
    'Core Dosya' => app_metric($db, "SELECT COUNT(*) FROM docs_files WHERE module_name = 'core'"),
    'Servis Dosya' => app_metric($db, "SELECT COUNT(*) FROM docs_files WHERE module_name = 'servis'"),
    'Genel Arsiv' => app_metric($db, "SELECT COUNT(*) FROM docs_files WHERE module_name = 'evrak'"),
];

$moduleOptions = ['evrak', 'core', 'cari', 'fatura', 'satis', 'stok', 'servis', 'kira', 'uretim', 'crm', 'tahsilat', 'ik'];
?>

<?php if ($feedback !== ''): ?>
    <div class="notice <?= strpos($feedback, 'error:') === 0 ? 'notice-error' : 'notice-ok' ?>">
        <?= app_h(strpos($feedback, 'error:') === 0 ? substr($feedback, 6) : 'Islem kaydedildi.') ?>
    </div>
<?php endif; ?>

<section class="module-grid">
    <?php foreach ($summary as $label => $value): ?>
        <div class="card">
            <small><?= app_h($label) ?></small>
            <strong><?= app_h((string) $value) ?></strong>
        </div>
    <?php endforeach; ?>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Yeni Evrak Yukle</h3>
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="upload_doc">
            <div>
                <label>Modul</label>
                <select name="module_name">
                    <?php foreach ($moduleOptions as $option): ?>
                        <option value="<?= app_h($option) ?>" <?= $prefillModule === $option ? 'selected' : '' ?>><?= app_h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Iliskili Tablo</label>
                <input name="related_table" placeholder="service_records / invoice_headers" value="<?= app_h($prefillRelatedTable) ?>">
            </div>
            <div>
                <label>Iliskili Kayit ID</label>
                <input name="related_id" type="number" min="1" placeholder="123" value="<?= $prefillRelatedId > 0 ? (int) $prefillRelatedId : '' ?>">
            </div>
            <div>
                <label>Dosya</label>
                <input type="file" name="doc_file" required>
            </div>
            <div class="full">
                <label>Notlar</label>
                <textarea name="notes" rows="3" placeholder="Evrak aciklamasi veya notu"></textarea>
            </div>
            <div class="full">
                <button type="submit">Evrak Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Arsiv Filtresi</h3>
        <form method="get" class="form-grid compact-form">
            <input type="hidden" name="module" value="evrak">
            <div>
                <label>Modul Secin</label>
                <select name="filter_module">
                    <option value="">Tum Moduller</option>
                    <?php foreach ($moduleOptions as $option): ?>
                        <option value="<?= app_h($option) ?>" <?= $moduleFilter === $option ? 'selected' : '' ?>><?= app_h($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Iliskili Tablo</label>
                <input name="filter_related_table" value="<?= app_h($relatedTableFilter) ?>" placeholder="invoice_headers">
            </div>
            <div>
                <label>Kayit ID</label>
                <input type="number" min="1" name="filter_related_id" value="<?= $relatedIdFilter > 0 ? (int) $relatedIdFilter : '' ?>" placeholder="123">
            </div>
            <div style="display:flex;align-items:end;">
                <button type="submit">Filtrele</button>
            </div>
        </form>

        <div class="list" style="margin-top:18px;">
            <div class="row"><div><strong style="font-size:1rem;">Dosya Konumu</strong><span>Belgeler /uploads/docs klasorune yazilir.</span></div><div class="ok">Hazir</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Arsiv Tablolari</strong><span>docs_files kayitlari modul ve kayit bazli iliskilendirilebilir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Audit</strong><span>Yukleme, goruntuleme, indirme ve silme islemleri audit loglara yazilir.</span></div><div class="ok">Aktif</div></div>
            <div class="row"><div><strong style="font-size:1rem;">Kayit Bazli Evrak</strong><span>Cari, fatura, servis ve kira satirlarindan dogrudan iliskili arsive gecis yapabilirsiniz.</span></div><div class="ok">Aktif</div></div>
        </div>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Evrak Arsivi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Dosya</th><th>Modul</th><th>Kayit</th><th>Tur</th><th>Tarih</th><th>Islem</th></tr></thead>
                <tbody>
                <?php foreach ($docs as $doc): ?>
                    <tr>
                        <td><?= app_h($doc['file_name']) ?></td>
                        <td><?= app_h($doc['module_name']) ?></td>
                        <td><?= app_h((string) (($doc['related_table'] ?: '-') . ' / ' . ($doc['related_id'] ?: '-'))) ?></td>
                        <td><?= app_h((string) ($doc['file_type'] ?: '-')) ?></td>
                        <td><?= app_h($doc['created_at']) ?></td>
                        <td>
                            <div class="stack">
                                <a href="index.php?module=evrak&filter_module=<?= urlencode($moduleFilter) ?>&filter_related_table=<?= urlencode($relatedTableFilter) ?>&filter_related_id=<?= (int) $relatedIdFilter ?>&preview_id=<?= (int) $doc['id'] ?>">Onizle</a>
                                <a href="<?= app_h(app_doc_view_url((int) $doc['id'])) ?>" target="_blank">Ac</a>
                                <a href="<?= app_h(app_doc_view_url((int) $doc['id'], true)) ?>">Indir</a>
                                <form method="post" onsubmit="return confirm('Bu evrak silinsin mi?');">
                                    <input type="hidden" name="action" value="delete_doc">
                                    <input type="hidden" name="doc_id" value="<?= (int) $doc['id'] ?>">
                                    <button type="submit">Sil</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Dosya Onizleme</h3>
        <?php if ($selectedDoc !== null): ?>
            <div class="list" style="margin-bottom:16px;">
                <div class="row"><div><strong style="font-size:1rem;"><?= app_h($selectedDoc['file_name']) ?></strong><span><?= app_h((string) ($selectedDoc['module_name'] . ' / ' . ($selectedDoc['related_table'] ?: '-') . ' / ' . ($selectedDoc['related_id'] ?: '-'))) ?></span></div><div class="ok"><?= app_h((string) ($selectedDoc['file_type'] ?: '-')) ?></div></div>
            </div>

            <?php if ($selectedDocPreviewable): ?>
                <iframe src="<?= app_h(app_doc_view_url((int) $selectedDoc['id'])) ?>" style="width:100%;min-height:520px;border:1px solid #eadfce;border-radius:18px;background:#fff;"></iframe>
            <?php else: ?>
                <p>Bu dosya turu tarayici onizlemesi icin uygun degil. Uygulama icinden acabilir veya indirebilirsiniz.</p>
            <?php endif; ?>

            <div class="stack" style="margin-top:16px;">
                <a href="<?= app_h(app_doc_view_url((int) $selectedDoc['id'])) ?>" target="_blank">Yeni Sekmede Ac</a>
                <a href="<?= app_h(app_doc_view_url((int) $selectedDoc['id'], true)) ?>">Dosyayi Indir</a>
            </div>
        <?php else: ?>
            <p>Onizlemek icin listeden bir evrak secin.</p>
        <?php endif; ?>
    </div>
</section>
