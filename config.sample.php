<?php
// Sao chép file này thành config.php rồi sửa thông tin kết nối.
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'tckt',
        'user'    => 'tckt',
        'pass'    => 'doi_mat_khau',
    ],
    // Múi giờ
    'timezone' => 'Asia/Ho_Chi_Minh',
    // Tên cookie phiên đăng nhập
    'session_name' => 'TCKTSESS',
    // Bật true khi chạy qua HTTPS để cookie chỉ gửi qua HTTPS
    'secure_cookie' => false,
    // Proxy ra Internet (nếu máy chủ bệnh viện phải đi qua proxy), ví dụ 'http://10.0.0.1:3128'
    'http_proxy' => '',
];
