<?php

declare(strict_types=1);

if (!$db || !$ready) {
    echo '<div class="card"><h3>Kurulum gerekli</h3><p>Personel ve IK modulu icin once veritabaninin bagli olmasi gerekir.</p></div>';
    return;
}

function hr_redirect(string $result): void
{
    app_redirect('index.php?module=ik&ok=' . urlencode($result));
}

function hr_build_filters(): array
{
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'department_id' => trim((string) ($_GET['department_id'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'employee_sort' => trim((string) ($_GET['employee_sort'] ?? 'id_desc')),
        'shift_sort' => trim((string) ($_GET['shift_sort'] ?? 'date_desc')),
        'employee_page' => max(1, (int) ($_GET['employee_page'] ?? 1)),
        'shift_page' => max(1, (int) ($_GET['shift_page'] ?? 1)),
    ];
}

function hr_selected_ids(): array
{
    $values = $_POST['employee_ids'] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0));
}

$action = $_POST['action'] ?? null;
$feedback = $_GET['ok'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_department') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Departman adi zorunludur.');
            }

            $stmt = $db->prepare('INSERT INTO hr_departments (name) VALUES (:name)');
            $stmt->execute(['name' => $name]);

            app_audit_log('ik', 'create_department', 'hr_departments', (int) $db->lastInsertId(), $name . ' departmani olusturuldu.');
            hr_redirect('department');
        }

        if ($action === 'create_employee') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            if ($fullName === '') {
                throw new RuntimeException('Personel adi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO hr_employees (department_id, user_id, full_name, title, phone, email, start_date, status)
                VALUES (:department_id, :user_id, :full_name, :title, :phone, :email, :start_date, :status)
            ');
            $stmt->execute([
                'department_id' => (int) ($_POST['department_id'] ?? 0) ?: null,
                'user_id' => (int) ($_POST['user_id'] ?? 0) ?: null,
                'full_name' => $fullName,
                'title' => trim((string) ($_POST['title'] ?? '')) ?: null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'start_date' => trim((string) ($_POST['start_date'] ?? '')) ?: null,
                'status' => isset($_POST['status']) ? 1 : 0,
            ]);

            app_audit_log('ik', 'create_employee', 'hr_employees', (int) $db->lastInsertId(), $fullName . ' personeli olusturuldu.');
            hr_redirect('employee');
        }

        if ($action === 'create_shift') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $shiftDate = trim((string) ($_POST['shift_date'] ?? ''));
            if ($employeeId <= 0 || $shiftDate === '') {
                throw new RuntimeException('Personel ve vardiya tarihi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO hr_shifts (employee_id, shift_date, start_time, end_time, status)
                VALUES (:employee_id, :shift_date, :start_time, :end_time, :status)
            ');
            $stmt->execute([
                'employee_id' => $employeeId,
                'shift_date' => $shiftDate,
                'start_time' => trim((string) ($_POST['start_time'] ?? '')) ?: null,
                'end_time' => trim((string) ($_POST['end_time'] ?? '')) ?: null,
                'status' => trim((string) ($_POST['status'] ?? 'planlandi')) ?: 'planlandi',
            ]);

            app_audit_log('ik', 'create_shift', 'hr_shifts', (int) $db->lastInsertId(), 'Vardiya kaydi olusturuldu.');
            hr_redirect('shift');
        }

        if ($action === 'create_assignment') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $assignmentType = trim((string) ($_POST['assignment_type'] ?? ''));
            if ($employeeId <= 0 || $assignmentType === '') {
                throw new RuntimeException('Personel ve zimmet tipi zorunludur.');
            }

            $stmt = $db->prepare('
                INSERT INTO hr_assignments (employee_id, assignment_type, description, assigned_at)
                VALUES (:employee_id, :assignment_type, :description, :assigned_at)
            ');
            $stmt->execute([
                'employee_id' => $employeeId,
                'assignment_type' => $assignmentType,
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'assigned_at' => trim((string) ($_POST['assigned_at'] ?? '')) ?: date('Y-m-d H:i:s'),
            ]);

            app_audit_log('ik', 'create_assignment', 'hr_assignments', (int) $db->lastInsertId(), 'Zimmet kaydi olusturuldu.');
            hr_redirect('assignment');
        }

        if ($action === 'update_employee') {
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                throw new RuntimeException('Gecerli personel secilmedi.');
            }

            $stmt = $db->prepare('
                UPDATE hr_employees
                SET department_id = :department_id, title = :title, phone = :phone, email = :email, start_date = :start_date, status = :status
                WHERE id = :id
            ');
            $stmt->execute([
                'department_id' => (int) ($_POST['department_id'] ?? 0) ?: null,
                'title' => trim((string) ($_POST['title'] ?? '')) ?: null,
                'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
                'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
                'start_date' => trim((string) ($_POST['start_date'] ?? '')) ?: null,
                'status' => trim((string) ($_POST['status'] ?? '1')) === '1' ? 1 : 0,
                'id' => $employeeId,
            ]);

            app_audit_log('ik', 'update_employee', 'hr_employees', $employeeId, 'Personel bilgileri guncellendi.');
            hr_redirect('employee_update');
        }

        if ($action === 'bulk_update_employee') {
            $employeeIds = hr_selected_ids();

            if ($employeeIds === []) {
                throw new RuntimeException('Toplu guncelleme icin personel secilmedi.');
            }

            $departmentId = (int) ($_POST['bulk_department_id'] ?? 0) ?: null;
            $status = trim((string) ($_POST['bulk_status'] ?? ''));
            $statusValue = $status === '' ? null : ($status === '1' ? 1 : 0);

            $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
            $params = array_merge([$departmentId, $statusValue], $employeeIds);
            $stmt = $db->prepare("UPDATE hr_employees SET department_id = COALESCE(?, department_id), status = COALESCE(?, status) WHERE id IN ({$placeholders})");
            $stmt->execute($params);

            app_audit_log('ik', 'bulk_update_employee', 'hr_employees', null, 'Toplu personel guncellemesi yapildi.');
            hr_redirect('employee_bulk_update');
        }
    } catch (Throwable $e) {
        $feedback = 'error:IK islemi tamamlanamadi. Lutfen bilgileri kontrol edip tekrar deneyin.';
    }
}

$filters = hr_build_filters();

$departments = app_fetch_all($db, 'SELECT id, name FROM hr_departments ORDER BY id DESC LIMIT 50');
$users = app_fetch_all($db, 'SELECT id, full_name, email FROM core_users ORDER BY id DESC LIMIT 50');
$employeeWhere = [];
$employeeParams = [];
if ($filters['search'] !== '') {
    $employeeWhere[] = '(e.full_name LIKE :search OR e.title LIKE :search OR e.phone LIKE :search OR e.email LIKE :search OR d.name LIKE :search)';
    $employeeParams['search'] = '%' . $filters['search'] . '%';
}
if ($filters['department_id'] !== '') {
    $employeeWhere[] = 'e.department_id = :department_id';
    $employeeParams['department_id'] = (int) $filters['department_id'];
}
if ($filters['status'] !== '') {
    $employeeWhere[] = 'e.status = :status';
    $employeeParams['status'] = (int) $filters['status'];
}
$employeeWhereSql = $employeeWhere ? 'WHERE ' . implode(' AND ', $employeeWhere) : '';

$employees = app_fetch_all($db, '
    SELECT e.id, e.full_name, e.title, e.phone, e.email, e.start_date, e.status, d.name AS department_name, u.full_name AS user_name
    FROM hr_employees e
    LEFT JOIN hr_departments d ON d.id = e.department_id
    LEFT JOIN core_users u ON u.id = e.user_id
    ' . $employeeWhereSql . '
    ORDER BY e.id DESC
    LIMIT 50
', $employeeParams);
$shifts = app_fetch_all($db, '
    SELECT s.shift_date, s.start_time, s.end_time, s.status, e.full_name
    FROM hr_shifts s
    INNER JOIN hr_employees e ON e.id = s.employee_id
    LEFT JOIN hr_departments d ON d.id = e.department_id
    ' . $employeeWhereSql . '
    ORDER BY s.id DESC
    LIMIT 50
', $employeeParams);
$assignments = app_fetch_all($db, '
    SELECT a.assignment_type, a.description, a.assigned_at, e.full_name
    FROM hr_assignments a
    INNER JOIN hr_employees e ON e.id = a.employee_id
    LEFT JOIN hr_departments d ON d.id = e.department_id
    ' . $employeeWhereSql . '
    ORDER BY a.id DESC
    LIMIT 50
', $employeeParams);

$employees = app_sort_rows($employees, $filters['employee_sort'], [
    'id_desc' => ['id', 'desc'],
    'name_asc' => ['full_name', 'asc'],
    'department_asc' => ['department_name', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$shifts = app_sort_rows($shifts, $filters['shift_sort'], [
    'date_desc' => ['shift_date', 'desc'],
    'date_asc' => ['shift_date', 'asc'],
    'status_asc' => ['status', 'asc'],
]);
$employeesPagination = app_paginate_rows($employees, $filters['employee_page'], 10);
$shiftsPagination = app_paginate_rows($shifts, $filters['shift_page'], 10);
$employees = $employeesPagination['items'];
$shifts = $shiftsPagination['items'];

$summary = [
    'Departman' => app_table_count($db, 'hr_departments'),
    'Personel' => app_table_count($db, 'hr_employees'),
    'Aktif Personel' => app_metric($db, 'SELECT COUNT(*) FROM hr_employees WHERE status = 1'),
    'Vardiya Kaydi' => app_table_count($db, 'hr_shifts'),
    'Zimmet Kaydi' => app_table_count($db, 'hr_assignments'),
];
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

<section class="card">
    <h3>IK Arama ve Filtre</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="ik">
        <div>
            <label>Arama</label>
            <input name="search" value="<?= app_h($filters['search']) ?>" placeholder="Personel, unvan, telefon, e-posta">
        </div>
        <div>
            <label>Departman</label>
            <select name="department_id">
                <option value="">Tum departmanlar</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>" <?= $filters['department_id'] === (string) $department['id'] ? 'selected' : '' ?>><?= app_h($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Durum</label>
            <select name="status">
                <option value="">Tum durumlar</option>
                <option value="1" <?= $filters['status'] === '1' ? 'selected' : '' ?>>Aktif</option>
                <option value="0" <?= $filters['status'] === '0' ? 'selected' : '' ?>>Pasif</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Filtrele</button>
        </div>
        <div>
            <label>&nbsp;</label>
            <a href="index.php?module=ik" style="display:inline-block;text-align:center;padding:10px 12px;border-radius:12px;background:#f3f4f6;color:#111827;font-weight:700;text-decoration:none;">Temizle</a>
        </div>
    </form>
</section>

<section class="card">
    <h3>Liste Ayarlari</h3>
    <form method="get" class="form-grid compact-form">
        <input type="hidden" name="module" value="ik">
        <input type="hidden" name="search" value="<?= app_h($filters['search']) ?>">
        <input type="hidden" name="department_id" value="<?= app_h($filters['department_id']) ?>">
        <input type="hidden" name="status" value="<?= app_h($filters['status']) ?>">
        <div>
            <label>Personel Siralama</label>
            <select name="employee_sort">
                <option value="id_desc" <?= $filters['employee_sort'] === 'id_desc' ? 'selected' : '' ?>>Yeni kayitlar</option>
                <option value="name_asc" <?= $filters['employee_sort'] === 'name_asc' ? 'selected' : '' ?>>Ad Soyad A-Z</option>
                <option value="department_asc" <?= $filters['employee_sort'] === 'department_asc' ? 'selected' : '' ?>>Departman A-Z</option>
                <option value="status_asc" <?= $filters['employee_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>Vardiya Siralama</label>
            <select name="shift_sort">
                <option value="date_desc" <?= $filters['shift_sort'] === 'date_desc' ? 'selected' : '' ?>>Tarih yeni-eski</option>
                <option value="date_asc" <?= $filters['shift_sort'] === 'date_asc' ? 'selected' : '' ?>>Tarih eski-yeni</option>
                <option value="status_asc" <?= $filters['shift_sort'] === 'status_asc' ? 'selected' : '' ?>>Durum A-Z</option>
            </select>
        </div>
        <div>
            <label>&nbsp;</label>
            <button type="submit">Uygula</button>
        </div>
    </form>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Departman</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_department">
            <div class="full">
                <label>Departman Adi</label>
                <input name="name" placeholder="Muhasebe / Operasyon / Teknik Servis" required>
            </div>
            <div class="full">
                <button type="submit">Departman Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Personel</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_employee">
            <div>
                <label>Ad Soyad</label>
                <input name="full_name" required>
            </div>
            <div>
                <label>Unvan</label>
                <input name="title" placeholder="Muhasebe Uzmani">
            </div>
            <div>
                <label>Departman</label>
                <select name="department_id">
                    <option value="">Seciniz</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>"><?= app_h($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Kullanici Hesabi</label>
                <select name="user_id">
                    <option value="">Bagimsiz personel</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int) $user['id'] ?>"><?= app_h($user['full_name'] . ' / ' . $user['email']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Telefon</label>
                <input name="phone">
            </div>
            <div>
                <label>E-posta</label>
                <input type="email" name="email">
            </div>
            <div>
                <label>Baslama Tarihi</label>
                <input type="date" name="start_date">
            </div>
            <div class="check-row">
                <label><input type="checkbox" name="status" value="1" checked> Aktif</label>
            </div>
            <div class="full">
                <button type="submit">Personel Kaydet</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Vardiya</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_shift">
            <div>
                <label>Personel</label>
                <select name="employee_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>"><?= app_h($employee['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tarih</label>
                <input type="date" name="shift_date" required>
            </div>
            <div>
                <label>Baslangic</label>
                <input type="time" name="start_time">
            </div>
            <div>
                <label>Bitis</label>
                <input type="time" name="end_time">
            </div>
            <div class="full">
                <label>Durum</label>
                <input name="status" value="planlandi" placeholder="planlandi / geldi / izinli">
            </div>
            <div class="full">
                <button type="submit">Vardiya Kaydet</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>Zimmet ve Gorev</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="action" value="create_assignment">
            <div>
                <label>Personel</label>
                <select name="employee_id" required>
                    <option value="">Seciniz</option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>"><?= app_h($employee['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Tip</label>
                <input name="assignment_type" placeholder="Laptop / Araç / Saha Gorevi" required>
            </div>
            <div class="full">
                <label>Aciklama</label>
                <textarea name="description" rows="4" placeholder="Zimmet seri no, gorev notu, teslim bilgisi"></textarea>
            </div>
            <div>
                <label>Zimmet Tarihi</label>
                <input type="datetime-local" name="assigned_at">
            </div>
            <div class="full">
                <button type="submit">Zimmet Kaydet</button>
            </div>
        </form>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Personel Listesi</h3>
        <form method="post" class="compact-form" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap;">
            <input type="hidden" name="action" value="bulk_update_employee">
            <select name="bulk_department_id">
                <option value="">Departman Secin</option>
                <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>"><?= app_h($department['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="bulk_status">
                <option value="">Durum Secin</option>
                <option value="1">Aktif</option>
                <option value="0">Pasif</option>
            </select>
            <button type="submit">Secili Personelleri Guncelle</button>
        <div class="table-wrap">
            <table>
                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.employee-check').forEach((el)=>el.checked=this.checked)"></th><th>Ad Soyad</th><th>Departman</th><th>Unvan</th><th>Iletisim</th><th>Durum</th><th>Guncelle</th></tr></thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td><input class="employee-check" type="checkbox" name="employee_ids[]" value="<?= (int) $employee['id'] ?>"></td>
                        <td><?= app_h($employee['full_name']) ?></td>
                        <td><?= app_h($employee['department_name'] ?: '-') ?></td>
                        <td><?= app_h($employee['title'] ?: '-') ?></td>
                        <td><?= app_h(($employee['phone'] ?: '-') . ' / ' . ($employee['email'] ?: '-')) ?></td>
                        <td><?= (int) $employee['status'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                        <td>
                            <div class="stack">
                                <a href="hr_detail.php?id=<?= (int) $employee['id'] ?>">Detay</a>
                                <form method="post" class="compact-form">
                                    <input type="hidden" name="action" value="update_employee">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <select name="department_id">
                                        <option value="">Departman</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?= (int) $department['id'] ?>" <?= ($employee['department_name'] ?? '') === $department['name'] ? 'selected' : '' ?>><?= app_h($department['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input name="title" value="<?= app_h((string) ($employee['title'] ?? '')) ?>" placeholder="Unvan">
                                    <input name="phone" value="<?= app_h((string) ($employee['phone'] ?? '')) ?>" placeholder="Telefon">
                                    <input name="email" value="<?= app_h((string) ($employee['email'] ?? '')) ?>" placeholder="E-posta">
                                    <input type="date" name="start_date" value="<?= app_h((string) ($employee['start_date'] ?? '')) ?>">
                                    <select name="status">
                                        <option value="1" <?= (int) $employee['status'] === 1 ? 'selected' : '' ?>>Aktif</option>
                                        <option value="0" <?= (int) $employee['status'] !== 1 ? 'selected' : '' ?>>Pasif</option>
                                    </select>
                                    <button type="submit">Kaydet</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php if ($employeesPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $employeesPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'ik', 'search' => $filters['search'], 'department_id' => $filters['department_id'], 'status' => $filters['status'], 'employee_sort' => $filters['employee_sort'], 'shift_sort' => $filters['shift_sort'], 'employee_page' => $page, 'shift_page' => $shiftsPagination['page']])) ?>"><?= $page === $employeesPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Departmanlar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Departman</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $department): ?>
                    <tr>
                        <td><?= app_h($department['name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($shiftsPagination['total_pages'] > 1): ?>
            <div class="stack" style="margin-top:14px;">
                <?php for ($page = 1; $page <= $shiftsPagination['total_pages']; $page++): ?>
                    <a href="index.php?<?= app_h(http_build_query(['module' => 'ik', 'search' => $filters['search'], 'department_id' => $filters['department_id'], 'status' => $filters['status'], 'employee_sort' => $filters['employee_sort'], 'shift_sort' => $filters['shift_sort'], 'employee_page' => $employeesPagination['page'], 'shift_page' => $page])) ?>"><?= $page === $shiftsPagination['page'] ? '[' . $page . ']' : (string) $page ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="module-grid module-grid-2">
    <div class="card">
        <h3>Son Vardiyalar</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Personel</th><th>Tarih</th><th>Saat</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($shifts as $shift): ?>
                    <tr>
                        <td><?= app_h($shift['full_name']) ?></td>
                        <td><?= app_h($shift['shift_date']) ?></td>
                        <td><?= app_h(($shift['start_time'] ?: '-') . ' / ' . ($shift['end_time'] ?: '-')) ?></td>
                        <td><?= app_h($shift['status'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Zimmet Gecmisi</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Personel</th><th>Tip</th><th>Aciklama</th><th>Tarih</th></tr></thead>
                <tbody>
                <?php foreach ($assignments as $assignment): ?>
                    <tr>
                        <td><?= app_h($assignment['full_name']) ?></td>
                        <td><?= app_h($assignment['assignment_type']) ?></td>
                        <td><?= app_h($assignment['description'] ?: '-') ?></td>
                        <td><?= app_h($assignment['assigned_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
