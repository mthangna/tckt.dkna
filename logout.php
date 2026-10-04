<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
if (is_post()) {
    csrf_check();
    logout();
}
redirect('login.php');
