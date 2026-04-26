<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$message = null;
$error = null;
$config = app_config();
$dbReady = app_database_ready();

if (!app_is_local_request() || $dbReady) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="tr">
    <head><meta charset="utf-8"><title>Erisim Engellendi</title></head>
    <body><h1>Erisim engellendi.</h1><p>Kurulum sayfasi sadece ilk kurulumda ve localhost uzerinden erisilebilir.</p></body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        app_require_csrf();
    } catch (Throwable $e) {
        $error = 'Guvenlik dogrulamasi basarisiz oldu. Sayfayi yenileyip tekrar deneyin.';
    }

    $dbConfig = [
        'host' => trim($_POST['host'] ?? '127.0.0.1'),
        'port' => trim($_POST['port'] ?? '3306'),
        'database' => trim($_POST['database'] ?? 'galancy'),
        'username' => trim($_POST['username'] ?? 'root'),
        'password' => (string) ($_POST['password'] ?? ''),
        'charset' => 'utf8mb4',
    ];

    if ($error === null) {
        try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['charset']),
            $dbConfig['username'],
            $dbConfig['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $schema = str_replace(
            ["CREATE DATABASE IF NOT EXISTS galancy", "USE galancy;"],
            ["CREATE DATABASE IF NOT EXISTS `{$dbConfig['database']}`", "USE `{$dbConfig['database']}`;"],
            file_get_contents(__DIR__ . '/database/schema.sql')
        );

        $seed = str_replace(
            'USE galancy;',
            "USE `{$dbConfig['database']}`;",
            file_get_contents(__DIR__ . '/database/seed.sql')
        );

        app_run_sql_batch($pdo, $schema);
        app_run_sql_batch($pdo, $seed);
        app_write_local_config($dbConfig);

        $message = 'Kurulum tamamlandi. Ilk giristen sonra yonetici sifresini guncelleyin.';
        $config = app_config();
        } catch (Throwable $e) {
            $error = 'Kurulum sirasinda bir hata olustu. Veritabani bilgilerini kontrol edin.';
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurulum | <?= htmlspecialchars($config['app_name']) ?></title>
    <style>
        :root { --bg:#f6f1e8; --card:#fffdf8; --ink:#1f2937; --muted:#6b7280; --line:#e7dcc7; --accent:#b45309; --accent-soft:#fde7c7; --success:#166534; --danger:#991b1b; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:"Segoe UI",sans-serif; color:var(--ink); background:radial-gradient(circle at top left,#facc15 0,transparent 18rem),linear-gradient(135deg,#f8f3ea,#efe5d5); min-height:100vh; padding:32px 16px; }
        .wrap { max-width:880px; margin:0 auto; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:24px; padding:28px; box-shadow:0 20px 60px rgba(92,64,18,.08); }
        h1 { margin:0 0 8px; font-size:2rem; }
        p { color:var(--muted); line-height:1.6; }
        form { display:grid; grid-template-columns:repeat(2,1fr); gap:16px; margin-top:24px; }
        label { display:block; font-weight:600; margin-bottom:6px; }
        input { width:100%; padding:12px 14px; border-radius:12px; border:1px solid var(--line); background:#fff; }
        .full { grid-column:1 / -1; }
        button { border:0; border-radius:14px; background:var(--accent); color:white; padding:14px 18px; font-weight:700; cursor:pointer; }
        .alert { border-radius:16px; padding:14px 16px; margin-top:18px; font-weight:600; }
        .success { background:#dcfce7; color:var(--success); }
        .danger { background:#fee2e2; color:var(--danger); }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px; margin-top:28px; }
        .mini { background:var(--accent-soft); border-radius:18px; padding:16px; }
        .mini strong { display:block; margin-bottom:6px; }
        a { color:var(--accent); }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1><?= htmlspecialchars($config['app_name']) ?></h1>
            <p>Bu kurulum ekrani, verdiginiz iki belgeye gore hazirlanan muhasebe, operasyon, servis, kira ve uretim omurgasini MySQL veritabanina kurar.</p>

            <?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="alert danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="post">
                <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                <div><label for="host">MySQL Host</label><input id="host" name="host" value="<?= htmlspecialchars($config['db']['host']) ?>"></div>
                <div><label for="port">Port</label><input id="port" name="port" value="<?= htmlspecialchars($config['db']['port']) ?>"></div>
                <div><label for="database">Veritabani</label><input id="database" name="database" value="<?= htmlspecialchars($config['db']['database']) ?>"></div>
                <div><label for="username">Kullanici</label><input id="username" name="username" value="<?= htmlspecialchars($config['db']['username']) ?>"></div>
                <div class="full"><label for="password">Sifre</label><input id="password" name="password" type="password" value="<?= htmlspecialchars($config['db']['password']) ?>"></div>
                <div class="full"><button type="submit">Sistemi Kur</button></div>
            </form>

            <div class="grid">
                <div class="mini"><strong>Kurulan moduller</strong>Core, cari, finans, POS, fatura, satis, stok, servis, kira, uretim, IK, evrak, CRM</div>
                <div class="mini"><strong>Guvenlik</strong>Kurulumdan sonra yonetici sifresini degistirin.</div>
                <div class="mini"><strong>Sonraki adim</strong>Kurulumdan sonra <a href="index.php">yonetim panelini</a> acabilirsiniz.</div>
            </div>
        </div>
    </div>
</body>
</html>
