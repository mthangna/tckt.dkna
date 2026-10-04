<?php
declare(strict_types=1);

/** Dữ liệu mẫu để xem thử. Giá, số tài khoản, khoảng cách đều là số liệu minh hoạ. */
function install_demo_data(int $adminId): void
{
    $pdo = db();
    $pw = password_hash('demo1234', PASSWORD_DEFAULT);
    $u = $pdo->prepare('INSERT IGNORE INTO users (username, password_hash, full_name, role) VALUES (?,?,?,?)');
    $u->execute(['dieuhanh', $pw, 'Nguyễn Thị Điều Hành', 'mod']);
    $u->execute(['ketoan', $pw, 'Trần Văn Kế Toán', 'user']);

    $pdo->prepare('INSERT INTO bank_accounts (bank_bin, bank_name, account_no, account_name, is_default) VALUES (?,?,?,?,1)')
        ->execute(['970418', 'BIDV', '1234567890', 'BENH VIEN HUU NGHI DA KHOA NGHE AN']);

    $fac = $pdo->prepare('INSERT INTO facilities (code, name, address, distance_km, is_home) VALUES (?,?,?,?,?)');
    foreach ([
        ['40001', 'Bệnh viện Hữu nghị Đa khoa Nghệ An', 'Km5 Đại lộ Lê Nin, Vinh, Nghệ An', 0, 1],
        ['01929', 'Bệnh viện Bạch Mai', '78 Giải Phóng, Đống Đa, Hà Nội', 295, 0],
        ['01003', 'Bệnh viện Hữu nghị Việt Đức', '40 Tràng Thi, Hoàn Kiếm, Hà Nội', 293, 0],
        ['01009', 'Bệnh viện K Trung ương', '30 Cầu Bươu, Thanh Trì, Hà Nội', 300, 0],
        ['01016', 'Bệnh viện Nhi Trung ương', '18/879 La Thành, Đống Đa, Hà Nội', 296, 0],
        ['46001', 'Bệnh viện Trung ương Huế', '16 Lê Lợi, TP Huế', 368, 0],
        ['79001', 'Bệnh viện Chợ Rẫy', '201B Nguyễn Chí Thanh, Quận 5, TP.HCM', 1420, 0],
        ['01010', 'Bệnh viện Phổi Trung ương', '463 Hoàng Hoa Thám, Ba Đình, Hà Nội', 298, 0],
    ] as $f) {
        $fac->execute($f);
    }

    $pdo->prepare("INSERT INTO fuel_prices (fuel_name, price, effective_at, source, created_by) VALUES (?,?,?,?,?)")
        ->execute(['Xăng E5 RON 92-II', 26390, '2026-10-01 15:00:00', 'Nhập tay (demo) – giá vùng 1 ngày 01/10/2026', $adminId]);

    // Danh mục giá mẫu
    $ins = $pdo->prepare('INSERT INTO price_items (category, equiv_code, tech_code, name, name_search, unit, price, decision_name, decision_date, effective_from, effective_to)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $qdOld = 'Quyết định số 1234/QĐ-SYT của Sở Y tế Nghệ An (mẫu)';
    $qdNew = 'Quyết định số 2345/QĐ-SYT của Sở Y tế Nghệ An (mẫu)';
    $bhyt = [
        ['Khám Nội', 'lần', 38700], ['Khám Ngoại', 'lần', 38700], ['Khám Nhi', 'lần', 38700], ['Khám Sản phụ khoa', 'lần', 38700],
        ['Khám Tai mũi họng', 'lần', 38700], ['Khám Răng hàm mặt', 'lần', 38700], ['Khám Mắt', 'lần', 38700], ['Khám Da liễu', 'lần', 38700],
        ['Ngày giường bệnh Hồi sức tích cực (ICU)', 'ngày', 789000], ['Ngày giường bệnh Nội khoa loại 1', 'ngày', 218000],
        ['Ngày giường bệnh Ngoại khoa loại 1', 'ngày', 288000], ['Siêu âm ổ bụng', 'lần', 49300], ['Siêu âm tim', 'lần', 233000],
        ['Siêu âm Doppler mạch máu', 'lần', 233000], ['Chụp X-quang ngực thẳng', 'lần', 65400], ['Chụp X-quang cột sống thắt lưng thẳng nghiêng', 'lần', 97200],
        ['Chụp CT sọ não không tiêm thuốc cản quang', 'lần', 536000], ['Chụp CT ngực có tiêm thuốc cản quang', 'lần', 970000],
        ['Chụp cộng hưởng từ (MRI) sọ não', 'lần', 1314000], ['Điện tim thường', 'lần', 34500], ['Điện não đồ', 'lần', 64300],
        ['Nội soi dạ dày tá tràng', 'lần', 244000], ['Nội soi đại tràng toàn bộ', 'lần', 329000], ['Tổng phân tích tế bào máu ngoại vi', 'lần', 46200],
        ['Định lượng Glucose', 'lần', 21500], ['Định lượng Creatinin', 'lần', 21500], ['Định lượng Ure', 'lần', 21500], ['Đo hoạt độ AST (GOT)', 'lần', 21500],
        ['Đo hoạt độ ALT (GPT)', 'lần', 21500], ['Định lượng HbA1c', 'lần', 101000], ['Tổng phân tích nước tiểu', 'lần', 27400],
        ['Định nhóm máu hệ ABO, Rh(D)', 'lần', 39100], ['Thời gian prothrombin (PT)', 'lần', 63100], ['Xét nghiệm HBsAg test nhanh', 'lần', 53600],
        ['Thay băng vết thương chiều dài dưới 15cm', 'lần', 57600], ['Khâu vết thương phần mềm dài dưới 10cm', 'lần', 178000],
        ['Phẫu thuật nội soi cắt ruột thừa', 'lần', 2794000], ['Phẫu thuật nội soi cắt túi mật', 'lần', 3528000], ['Mổ lấy thai lần đầu', 'lần', 2615000],
        ['Đỡ đẻ thường ngôi chỏm', 'lần', 706000], ['Lọc máu chu kỳ (thận nhân tạo)', 'lần', 543000], ['Truyền tĩnh mạch', 'lần', 21400],
        ['Tiêm bắp thịt', 'lần', 11400], ['Đặt ống thông dạ dày', 'lần', 90100], ['Vật lý trị liệu - Điện xung', 'lần', 41300],
    ];
    $i = 0;
    foreach ($bhyt as [$name, $unit, $price]) {
        $i++;
        $code = sprintf('%02d.%04d.%04d', ($i % 20) + 1, 100 + $i * 7, 1700 + $i);
        $tech = sprintf('KT%05d', 2000 + $i);
        if ($i % 4 === 0) {
            // Có lịch sử giá: bản cũ hết hiệu lực, bản mới đang áp dụng
            $old = (int)round($price * 0.93 / 100) * 100;
            $ins->execute(['bhyt', $code, $tech, $name, vn_unaccent($name), $unit, $old, $qdOld, '2024-12-20', '2025-01-01', '2026-06-30']);
            $ins->execute(['bhyt', $code, $tech, $name, vn_unaccent($name), $unit, $price, $qdNew, '2026-06-15', '2026-07-01', null]);
        } else {
            $ins->execute(['bhyt', $code, $tech, $name, vn_unaccent($name), $unit, $price, $qdNew, '2026-06-15', '2026-07-01', null]);
        }
    }
    $qdYc = 'Quyết định số 567/QĐ-BV của Giám đốc Bệnh viện (mẫu)';
    $yc = [
        ['Khám bệnh theo yêu cầu (Bác sĩ chuyên khoa)', 'lần', 150000], ['Khám bệnh theo yêu cầu (Phó giáo sư, Tiến sĩ)', 'lần', 300000],
        ['Giường bệnh theo yêu cầu phòng 1 giường', 'ngày', 1200000], ['Giường bệnh theo yêu cầu phòng 2 giường', 'ngày', 600000],
        ['Siêu âm 4D theo yêu cầu', 'lần', 350000], ['Nội soi dạ dày gây mê theo yêu cầu', 'lần', 1200000], ['Phẫu thuật theo yêu cầu - chọn kíp mổ', 'ca', 3000000],
        ['Chụp MRI theo yêu cầu ngoài giờ', 'lần', 2000000], ['Khám sức khỏe tổng quát gói cơ bản', 'gói', 1450000], ['Khám sức khỏe tổng quát gói nâng cao', 'gói', 3850000],
        ['Tầm soát ung thư gói nữ', 'gói', 4200000], ['Tầm soát ung thư gói nam', 'gói', 3900000], ['Chăm sóc hộ lý theo yêu cầu', 'ngày', 450000],
    ];
    $i = 0;
    foreach ($yc as [$name, $unit, $price]) {
        $i++;
        $ins->execute(['yeucau', sprintf('YC%03d', $i), null, $name, vn_unaccent($name), $unit, $price, $qdYc, '2026-03-10', '2026-04-01', null]);
    }
    $qdK = 'Quyết định số 890/QĐ-BV của Giám đốc Bệnh viện (mẫu)';
    $khac = [
        ['Sao bệnh án', 'bản', 50000], ['Cấp lại giấy ra viện', 'bản', 30000], ['Trích lục hồ sơ bệnh án', 'bộ', 100000],
        ['Vận chuyển người bệnh bằng xe cứu thương nội thành', 'chuyến', 250000], ['Vận chuyển người bệnh bằng xe cứu thương ngoại tỉnh', 'km', 15000],
        ['Gửi xe máy (ngày)', 'lượt', 5000], ['Gửi ô tô (ngày)', 'lượt', 30000], ['Bảo quản thi hài (ngày)', 'ngày', 500000],
        ['Khám sức khỏe lái xe', 'lần', 300000], ['Khám sức khỏe đi làm, đi học', 'lần', 250000], ['Giám định y khoa', 'lần', 800000],
    ];
    $i = 0;
    foreach ($khac as [$name, $unit, $price]) {
        $i++;
        $ins->execute(['khac', sprintf('DVK%03d', $i), null, $name, vn_unaccent($name), $unit, $price, $qdK, '2026-01-05', '2026-02-01', null]);
    }
}
