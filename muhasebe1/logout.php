<?php

declare(strict_types=1);

require __DIR__ . '/app_bootstrap.php';

app_logout();
app_redirect('login.php');
