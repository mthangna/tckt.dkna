<?php
declare(strict_types=1);
/**
 * Nâng cấp CSDL của bản đã cài lên phiên bản mới. Chạy bằng dòng lệnh, chạy lại nhiều lần không sao:
 *   php install/upgrade.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Chạy bằng dòng lệnh: php install/upgrade.php');
}
require dirname(__DIR__) . '/inc/bootstrap.php';
$pdo = db();

// 1) Nội dung chuyển khoản QR gồm mã điều trị + họ tên → cần cột dài hơn
$pdo->exec('ALTER TABLE payment_requests MODIFY transfer_content VARCHAR(100) NOT NULL');
echo "- payment_requests.transfer_content: VARCHAR(100)\n";

// 2) Bỏ cấu hình tiền tố nội dung CK (không còn dùng)
$pdo->exec("DELETE FROM settings WHERE name = 'qr_prefix'");

// 3) Bản demo: thêm tài khoản mẫu còn thiếu (vd tài khoản quản trị "quantri")
if (setting('demo_mode') === '1') {
    $pw = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
    $st = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)');
    foreach (demo_accounts() as [$name, $full, $role]) {
        $st->execute([$name, $pw, $full, $role]);
        if ($st->rowCount()) {
            echo "- Đã thêm tài khoản demo $name\n";
        }
    }
    // Giá KCB theo yêu cầu mẫu: bổ sung mã kỹ thuật (cột hiển thị mới)
    $n = $pdo->exec("UPDATE price_items SET tech_code = CONCAT('KT', equiv_code) WHERE category = 'yeucau' AND tech_code IS NULL AND equiv_code LIKE 'YC%'");
    echo "- Bổ sung mã kỹ thuật cho $n mục giá theo yêu cầu mẫu\n";
}
echo "Xong.\n";
