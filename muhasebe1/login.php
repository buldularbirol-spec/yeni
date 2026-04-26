<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

$config = app_config();
$error = '';
$info = '';
$db = app_db();
$timeoutNotice = app_session_timeout_notice();
$pendingTwoFactor = $db ? app_two_factor_pending() : null;
$existingUser = app_auth_user();
if ($existingUser !== null) {
    if ($db && !app_is_request_ip_allowed($db)) {
        app_handle_ip_access_denied('login_existing_session', (string) ($existingUser['email'] ?? ''));
        app_logout();
        $error = 'Bu IP adresi icin erisim izni bulunmuyor.';
    } else {
        app_redirect('index.php');
    }
}

if (isset($_GET['ip_blocked']) && $_GET['ip_blocked'] === '1' && $error === '') {
    $error = 'Bu IP adresi icin erisim izni bulunmuyor.';
}

if ($timeoutNotice && $error === '') {
    $error = 'Oturumunuz zaman asimi nedeniyle kapatildi. Lutfen yeniden giris yapin.';
    app_session_timeout_clear_notice();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginAction = trim((string) ($_POST['login_action'] ?? 'credentials')) ?: 'credentials';
    try {
        app_require_csrf();
    } catch (Throwable $e) {
        $error = 'Guvenlik dogrulamasi basarisiz. Sayfayi yenileyip tekrar deneyin.';
    }

    if ($loginAction === 'cancel_otp') {
        app_two_factor_clear();
        $pendingTwoFactor = null;
        $info = 'Iki adimli dogrulama beklemesi iptal edildi.';
    } elseif ($loginAction === 'verify_otp') {
        $pendingTwoFactor = $db ? app_two_factor_pending() : null;
        $otpCode = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));
        if ($error === '' && (!$db || !$pendingTwoFactor)) {
            $error = 'Dogrulanacak aktif bir kod bulunamadi. Lutfen yeniden giris yapin.';
        }

        if ($error === '' && (int) ($pendingTwoFactor['expires_at'] ?? 0) < time()) {
            app_audit_log('auth', 'two_factor_expired', 'core_users', (int) ($pendingTwoFactor['user_id'] ?? 0), 'Iki adimli dogrulama kodunun suresi doldu.');
            app_two_factor_clear();
            $pendingTwoFactor = null;
            $error = 'Dogrulama kodunun suresi doldu. Lutfen yeniden giris yapin.';
        }

        if ($error === '' && strlen($otpCode) !== 6) {
            $error = 'Dogrulama kodu 6 haneli olmalidir.';
        }

        if ($error === '' && $pendingTwoFactor) {
            $attempts = (int) ($pendingTwoFactor['attempts'] ?? 0) + 1;
            if (!hash_equals((string) ($pendingTwoFactor['code_hash'] ?? ''), hash('sha256', $otpCode))) {
                $pendingTwoFactor['attempts'] = $attempts;
                app_two_factor_store($pendingTwoFactor);
                app_audit_log('auth', 'two_factor_failed', 'core_users', (int) ($pendingTwoFactor['user_id'] ?? 0), 'Iki adimli dogrulama kodu hatali girildi.');
                if ($attempts >= 5) {
                    app_two_factor_clear();
                    $pendingTwoFactor = null;
                    $error = 'Cok fazla hatali kod girildi. Lutfen yeniden giris yapin.';
                } else {
                    $pendingTwoFactor = app_two_factor_pending();
                    $error = 'Dogrulama kodu hatali.';
                }
            } else {
                $user = app_auth_user_by_id((int) ($pendingTwoFactor['user_id'] ?? 0));
                if (!$user) {
                    app_two_factor_clear();
                    $pendingTwoFactor = null;
                    $error = 'Kullanici oturumu bulunamadi. Lutfen yeniden giris yapin.';
                } else {
                    app_auth_login_user($user);
                    app_audit_log('auth', 'two_factor_success', 'core_users', (int) $user['id'], 'Iki adimli dogrulama basariyla tamamlandi.');
                    app_two_factor_clear();
                    app_redirect('index.php');
                }
            }
        }
    } elseif ($loginAction === 'resend_otp') {
        $pendingTwoFactor = $db ? app_two_factor_pending() : null;
        if ($error === '' && (!$db || !$pendingTwoFactor)) {
            $error = 'Yeniden gonderilecek aktif bir kod bulunamadi.';
        }

        if ($error === '' && $pendingTwoFactor) {
            $user = app_auth_user_by_id((int) ($pendingTwoFactor['user_id'] ?? 0));
            if (!$user) {
                app_two_factor_clear();
                $pendingTwoFactor = null;
                $error = 'Kullanici bulunamadi. Lutfen yeniden giris yapin.';
            } else {
                $ttlMinutes = app_two_factor_ttl_minutes($db);
                try {
                    $code = app_two_factor_generate_code();
                    $delivery = app_two_factor_dispatch_email($db, $user, $code, $ttlMinutes);
                    $pendingTwoFactor = [
                        'user_id' => (int) $user['id'],
                        'email' => (string) $user['email'],
                        'code_hash' => hash('sha256', $code),
                        'expires_at' => time() + ($ttlMinutes * 60),
                        'attempts' => 0,
                        'delivery_mode' => (string) ($delivery['mode'] ?? 'mock'),
                    ];
                    app_two_factor_store($pendingTwoFactor);
                    app_audit_log('auth', 'two_factor_resend', 'core_users', (int) $user['id'], 'Iki adimli dogrulama kodu yeniden gonderildi.');
                    $info = 'Dogrulama kodu yeniden gonderildi.';
                    if (($delivery['preview_code'] ?? '') !== '') {
                        $info .= ' Test kodu: ' . $delivery['preview_code'];
                    }
                } catch (Throwable $e) {
                    $error = 'Dogrulama kodu gonderilemedi. Bildirim ayarlarini kontrol edin.';
                }
            }
        }
    } else {
        $limit = app_rate_limit_check('login:' . app_client_ip(), 10, 900);
        if (!$limit['allowed']) {
            $error = 'Cok fazla deneme yapildi. Lutfen daha sonra tekrar deneyin.';
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($error === '' && $db && !app_is_request_ip_allowed($db)) {
            app_handle_ip_access_denied('login_attempt', $email);
            $error = 'Bu IP adresi icin erisim izni bulunmuyor.';
        }

        if ($error !== '' && strpos($error, 'Cok fazla deneme') !== false) {
            app_audit_log(
                'auth',
                'login_rate_limited',
                'core_users',
                null,
                json_encode([
                    'email' => $email,
                    'reason' => 'rate_limited',
                    'reason_label' => 'Giris limiti asildi',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if ($error === '' && $db) {
            $user = app_auth_verify_credentials($email, $password);
            if ($user) {
                if (app_two_factor_enabled_for_user($db, $user)) {
                    $ttlMinutes = app_two_factor_ttl_minutes($db);
                    try {
                        $code = app_two_factor_generate_code();
                        $delivery = app_two_factor_dispatch_email($db, $user, $code, $ttlMinutes);
                        $pendingTwoFactor = [
                            'user_id' => (int) $user['id'],
                            'email' => (string) $user['email'],
                            'code_hash' => hash('sha256', $code),
                            'expires_at' => time() + ($ttlMinutes * 60),
                            'attempts' => 0,
                            'delivery_mode' => (string) ($delivery['mode'] ?? 'mock'),
                        ];
                        app_two_factor_store($pendingTwoFactor);
                        app_audit_log('auth', 'two_factor_challenge_created', 'core_users', (int) $user['id'], 'Iki adimli dogrulama kodu olusturuldu.');
                        $info = 'Dogrulama kodu e-posta adresinize gonderildi.';
                        if (($delivery['preview_code'] ?? '') !== '') {
                            $info .= ' Test kodu: ' . $delivery['preview_code'];
                        }
                    } catch (Throwable $e) {
                        $error = 'Dogrulama kodu gonderilemedi. Bildirim ayarlarini kontrol edin.';
                    }
                } else {
                    app_auth_login_user($user);
                    app_redirect('index.php');
                }
            }
        }

        if ($error === '' && !$pendingTwoFactor) {
            $error = 'E-posta veya sifre hatali.';
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giris | <?= app_h($config['app_name']) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css?family=Montserrat:200,300,400,500,600|Roboto:400&display=swap');

        :root {
            --bg: #f5f7fa;
            --surface: #ffffff;
            --ink: #212529;
            --muted: #64748b;
            --accent: #8253eb;
            --accent-strong: #6931e7;
            --border: #e6ecf8;
            --shadow: 0 30px 80px rgba(15, 23, 42, 0.12);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Montserrat', 'Roboto', sans-serif;
            color: var(--ink);
            background: var(--bg);
        }
        .body-bg-full {
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-size: cover;
            min-height: 100vh;
            overflow: hidden;
        }
        .wrapper {
            margin: 0;
            display: table;
            width: 100%;
            min-height: 100vh;
        }
        .row.container-min-full-height {
            display: flex;
            flex-wrap: wrap;
            min-height: 100vh;
        }
        .col-lg-8 { flex: 0 0 66.666667%; max-width: 66.666667%; }
        .col-lg-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
        .p-3 { padding: 1rem; }
        .w-50 { width: min(100%, 520px); }
        .mb-4 { margin-bottom: 1.5rem; }
        .text-center { text-align: center; }
        .login-left,
        .login-right {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .login-left {
            background: #ffffff;
            padding: 3rem 2rem;
        }
        .login-left .form-group {
            margin-bottom: 2rem;
            width: 100%;
        }
        .login-left label {
            display: block;
            margin-bottom: .75rem;
            letter-spacing: 1px;
            color: #6b7280;
            text-transform: uppercase;
            font-size: .8rem;
        }
        .login-left input {
            width: 100%;
            text-align: center;
            font-size: 1.05rem;
            border: 0;
            letter-spacing: -1px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 500;
            color: #444;
            padding: 0 0 .9em;
            background-color: transparent;
            background-position: center bottom, center calc(99%);
            background-repeat: no-repeat;
            background-size: 0 2px, 100% 3px;
            transition: background 0s ease-out 0s;
            background-image: linear-gradient(#8253eb, #8253eb), linear-gradient(#eef1f2, #eef1f2);
        }
        .login-left input:focus {
            background-size: 100% 2px, 100% 3px;
            outline: none;
            transition-duration: .3s;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            border: 1px solid transparent;
            border-radius: 999px;
            padding: 1rem 1.4rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-block { width: 100%; }
        .btn-rounded { border-radius: 999px; }
        .btn-md { padding: 1rem 1.6rem; }
        .text-uppercase { text-transform: uppercase; }
        .fw-600 { font-weight: 600; }
        .btn-color-scheme {
            color: #fff;
            background-color: var(--accent);
            border-color: var(--accent);
        }
        .btn-color-scheme:hover {
            background-color: var(--accent-strong);
        }
        .btn-outline-inverse {
            color: #fff;
            background: transparent;
            border: 1px solid rgba(255,255,255,.8);
        }
        .btn-outline-inverse:hover {
            background: rgba(255,255,255,.15);
            color: #fff;
        }
        .heading-font-family { font-family: 'Montserrat', sans-serif; }
        .ripple { transition: box-shadow .2s ease, transform .2s ease; }
        .ripple:hover { box-shadow: 0 20px 45px rgba(0,0,0,.14); }
        .pd-lr-60 { padding-left: 3.75rem; padding-right: 3.75rem; }
        .mt-4 { margin-top: 1.5rem; }
        .alert {
            width: 100%;
            padding: 1rem 1.15rem;
            border-radius: 18px;
            margin-bottom: 1.2rem;
            font-weight: 700;
        }
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        .alert-info {
            background: #eff6ff;
            color: #1d4ed8;
        }
        .hint {
            margin-top: 1.5rem;
            font-size: .95rem;
            color: var(--muted);
        }
        .login-right {
            background-image: url('assets/demo/login-page-bg.jpg');
            background-position: right;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-size: cover;
            color: #fff;
            text-shadow: 0 1px 15px rgba(0, 0, 0, .18);
            min-height: 100vh;
            padding: 2.5rem 1.5rem;
        }
        .login-content {
            width: 75%;
            max-width: 360px;
            text-align: center;
        }
        .login-content h2 {
            margin-bottom: 1.5rem;
            font-weight: 300;
            font-size: 2.25rem;
            color: #fff;
        }
        .login-content p {
            margin: 0;
            line-height: 1.9;
            color: rgba(255,255,255,.9);
            font-size: 1rem;
        }
        .list-inline {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            padding: 0;
            margin: 1.5rem 0 0;
            list-style: none;
            justify-content: center;
        }
        .list-inline-item a {
            color: rgba(255,255,255,.85);
            font-size: .82rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            text-decoration: none;
        }
        .list-inline-item a:hover { color: #fff; }
        .fw-300 { font-weight: 300; }
        .letter-spacing-minus { letter-spacing: -0.04em; }
        .fs-13 { font-size: .8125rem; }
        .mr-t-200 { margin-top: 12rem; }
        @media (max-width: 992px) {
            .col-lg-8, .col-lg-4 { flex: 0 0 100%; max-width: 100%; }
            .login-right { display: none; }
            .login-left { padding: 3rem 1.5rem; }
        }
    </style>
</head>
<body class="body-bg-full profile-page">
    <div id="wrapper" class="wrapper">
        <div class="row container-min-full-height">
            <div class="col-lg-8 p-3 login-left">
                <div class="w-50">
                    <h2 class="mb-4 text-center">Welcome back!</h2>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= app_h($error) ?></div>
                    <?php endif; ?>
                    <?php if ($info !== ''): ?>
                        <div class="alert alert-info"><?= app_h($info) ?></div>
                    <?php endif; ?>

                    <?php if ($pendingTwoFactor): ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="login_action" value="verify_otp">
                            <div class="form-group">
                                <label for="otp_code">Dogrulama Kodu</label>
                                <input id="otp_code" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6 haneli kod" required>
                            </div>
                            <button class="btn btn-color-scheme btn-block" type="submit">Kodu Dogrula</button>
                        </form>
                        <form method="post" style="margin-top:1rem;">
                            <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="login_action" value="resend_otp">
                            <button class="btn btn-outline-inverse btn-block" type="submit">Kodu Yeniden Gonder</button>
                        </form>
                        <form method="post" style="margin-top:1rem;">
                            <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="login_action" value="cancel_otp">
                            <button class="btn btn-outline-inverse btn-block" type="submit">Iptal Et</button>
                        </form>
                        <div class="hint">Kod <?= app_h((string) ($pendingTwoFactor['email'] ?? '-')) ?> adresine gonderildi. Gecerlilik suresi sinirlidir.</div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= app_h(app_csrf_token()) ?>">
                            <input type="hidden" name="login_action" value="credentials">
                            <div class="form-group">
                                <label for="email">E-posta</label>
                                <input id="email" name="email" type="email" value="" required>
                            </div>
                            <div class="form-group">
                                <label for="password">Sifre</label>
                                <input id="password" name="password" type="password" value="" required>
                            </div>
                            <button class="btn btn-color-scheme btn-block" type="submit">Giris Yap</button>
                        </form>
                    <?php endif; ?>

                    <div class="hint">Giris bilgileri sadece yetkili kullanicilar tarafindan bilinmelidir.</div>
                </div>
            </div>
            <div class="col-lg-4 login-right d-lg-flex d-none container-min-full-height">
                <div class="login-content">
                    <h2 class="mb-4 text-center fw-300">New here?</h2>
                    <p class="heading-font-family fw-300 letter-spacing-minus">Sign up and discover the many great features that our app provides</p>
                    <a class="btn btn-rounded btn-md btn-outline-inverse text-uppercase fw-600 ripple pd-lr-60 mr-t-200" href="#">Sign Up</a>
                    <ul class="list-inline mt-4 heading-font-family text-uppercase fs-13 mr-t-20">
                        <li class="list-inline-item"><a href="#">Home</a></li>
                        <li class="list-inline-item"><a href="#">About</a></li>
                        <li class="list-inline-item"><a href="#">Contact</a></li>
                        <li class="list-inline-item"><a href="#">Careers</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
