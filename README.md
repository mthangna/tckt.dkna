# Công cụ nội bộ Phòng Tài chính Kế toán – BV HNĐK Nghệ An

Ứng dụng web PHP + MySQL chạy trên máy chủ nội bộ, gồm:

| Trang | File | Quyền |
|---|---|---|
| Tổng quan | `index.php` | mọi người dùng |
| Thanh toán QR (VietQR, tự cập nhật khi tiền về) | `qr.php` | mọi người dùng |
| Tra cứu bảng giá (BHYT / theo yêu cầu / dịch vụ khác) | `prices.php` | mọi người dùng |
| Phiếu chi hỗ trợ vận chuyển + in phiếu | `voucher.php`, `voucher_print.php` | mọi người dùng (hủy phiếu: điều hành) |
| Báo cáo, xuất Excel | `reports.php` | user xem phần mình lập; điều hành/quản trị xem toàn bộ |
| Đối soát ngân hàng (sao kê, khớp thủ công) | `reconcile.php` | điều hành, quản trị |
| Cấu hình: tài khoản nhận tiền, danh mục giá (Excel), cơ sở KCB, giá xăng | `settings_*.php` | điều hành, quản trị |
| Cấu hình chung, người dùng, nhật ký | `settings_general.php`, `users.php`, `audit.php` | quản trị |

Mỗi chức năng là một trang riêng, chỉ nạp đúng file JS của trang đó. Thư viện giao diện (Bootstrap 5, Bootstrap Icons, thư viện vẽ QR) đã đóng gói sẵn trong `assets/vendor`, **không cần Internet** để chạy.

## 1. Yêu cầu máy chủ

- PHP **8.0 trở lên** với các tiện ích: `pdo_mysql`, `mbstring`, `zip`, `xml` (SimpleXML, DOM), `curl`, `json`.
- MySQL 5.7+ hoặc MariaDB 10.3+.
- Apache (đã kèm `.htaccess` chặn truy cập thư mục `inc/`, `install/`, `storage/` và `config.php`), hoặc Nginx/IIS (tự cấu hình chặn các thư mục trên – xem mục 5).

## 2. Cài đặt

1. Chép toàn bộ thư mục vào web root, ví dụ `C:\xampp\htdocs\tckt` hoặc `/var/www/html/tckt`.
2. Tạo CSDL:
   ```sql
   CREATE DATABASE tckt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'tckt'@'localhost' IDENTIFIED BY 'mat_khau_manh';
   GRANT ALL ON tckt.* TO 'tckt'@'localhost';
   ```
3. Sao chép `config.sample.php` thành `config.php`, điền thông tin CSDL. Nếu máy chủ ra Internet qua proxy, điền `http_proxy` (dùng cho nút lấy giá xăng).
4. Cho phép web server ghi vào thư mục `storage/`.
5. Mở `http://<máy-chủ>/tckt/install.php`, tạo tài khoản quản trị. Tích "Nạp dữ liệu mẫu" nếu muốn xem thử.
   Sau khi cài, file `storage/install.lock` được tạo để khoá trang cài đặt.

**Dữ liệu mẫu (khi chọn demo):** tài khoản `dieuhanh` (điều hành) và `ketoan` (người dùng), mật khẩu `demo1234`; một tài khoản BIDV **giả** `1234567890`; 8 cơ sở KCB với khoảng cách ước lượng; ~70 mục giá mẫu (không phải giá thật). Trước khi dùng thật: xoá/khoá các tài khoản mẫu, sửa tài khoản nhận tiền, tải danh mục giá thật, tắt chế độ demo ở Cấu hình chung.

## 3. Các chức năng chính

### Thanh toán QR
- Sinh mã **VietQR chuẩn EMVCo/NAPAS** (mọi app ngân hàng quét được), vẽ ngay trên trình duyệt, không gửi dữ liệu người bệnh ra ngoài.
- Nội dung chuyển khoản: `<tiền tố> <mã điều trị>`, ví dụ `DKNA 2610001234` (tiền tố đổi ở Cấu hình chung).
- Trang tự hỏi trạng thái mỗi 3 giây; chuyển sang **"Đã nhận tiền"** khi có giao dịch tiền vào khớp **nội dung + số tiền**.
- Nguồn giao dịch tiền vào:
  1. **Webhook** `api/bank_webhook.php` – hỗ trợ sẵn định dạng của SePay; khi có tài liệu BIDV iConnect chỉ cần thêm bộ chuyển đổi trong hàm `normalize_webhook()`. Xác thực bằng header `Authorization: Apikey <khoá>` (tạo khoá ở Cấu hình chung).
  2. **Sao kê Excel** tải từ BIDV iBank → trang Đối soát ngân hàng (tự nhận diện cột Ngày giao dịch / Số tham chiếu / Số tiền ghi có / Nội dung; không nạp trùng).
  3. **Xác nhận thủ công** (điều hành) – bắt buộc ghi căn cứ, lưu nhật ký.
  4. Nút **"Giả lập tiền về"** chỉ có khi bật chế độ demo.

### Bảng giá dịch vụ
- 3 nhóm: KBCB BHYT, KCB theo yêu cầu, dịch vụ khác. Tải dữ liệu bằng AJAX theo trang (20/50/100 mục), tìm theo tên có dấu/không dấu, mã tương đương, mã kỹ thuật.
- Rê chuột lên dòng: quyết định ban hành, ngày ban hành, ngày áp dụng, mã tương đương, mã kỹ thuật. Bấm vào dòng: lịch sử giá của dịch vụ.
- Công tắc "Xem cả giá cũ hết hiệu lực".
- Tải lên Excel (.xlsx) hoặc CSV: nhận diện cột theo tiêu đề, có **bước xem trước** (mới / đổi giá / sửa / bỏ qua / lỗi) trước khi ghi. Giá mới có ngày áp dụng muộn hơn sẽ tự đóng giá cũ (giá cũ vẫn lưu để tra cứu). Tải file mẫu ngay trên trang.
- File `.xls` (Excel 97–2003) chưa đọc được: mở bằng Excel và "Lưu thành .xlsx".

### Phiếu chi hỗ trợ vận chuyển
- Số tiền = km × định mức (mặc định 0,2 lít/km) × giá xăng đang áp dụng; làm tròn theo cấu hình (đồng/100/1.000).
- Chọn cơ sở tiếp nhận từ danh mục → tự điền khoảng cách (sửa được); có tuỳ chọn nhập tay cơ sở ngoài danh mục.
- Số phiếu tự tăng theo năm (`0001/2026`), số tiền bằng chữ đọc đúng tiếng Việt, mẫu in khổ A5 ngang có Quyển số / Số / Nợ / Có và 5 chữ ký (thông tin đơn vị, mẫu số, tên người ký cấu hình được).
- Người dùng thường không sửa được giá xăng trên phiếu; điều hành sửa được. Hủy phiếu phải ghi lý do, phiếu hủy vẫn lưu và in có dấu "ĐÃ HỦY".

### Giá xăng
- Lưu lịch sử giá theo thời điểm áp dụng; phiếu đã lập giữ nguyên giá lúc lập.
- Nút **"Lấy giá … hiện hành"** lấy bảng giá bán lẻ từ nguồn (mặc định trang chủ Petrolimex `https://www.petrolimex.com.vn/index.html` – ứng dụng gọi thẳng API JSON công khai mà trang này dùng để hiển thị giá; nguồn khác thì đọc bảng HTML) và hiển thị Vùng 1/Vùng 2 bên cạnh để tham khảo; chỉ áp dụng khi người dùng bấm dùng và Lưu. Địa chỉ nguồn và từ khoá đổi được nếu trang nguồn đổi cấu trúc.

### Báo cáo
- Phiếu chi vận chuyển (lọc theo ngày, người lập, trạng thái, nơi tiếp nhận; tổng hợp theo cơ sở), Thu tiền qua QR, Tổng hợp theo ngày. Tất cả **xuất Excel**.

## 4. Bảo mật đã có
- Mật khẩu băm `password_hash`; khoá đăng nhập 15 phút sau 5 lần sai/IP; đổi phiên khi đăng nhập.
- Chống CSRF cho mọi thao tác ghi; câu lệnh SQL dùng tham số; mọi dữ liệu hiển thị đều được escape.
- Phân quyền kiểm tra ở máy chủ (không chỉ ẩn menu). Nhật ký thao tác (`audit.php`).

## 5. Khuyến nghị khi triển khai
- Chạy HTTPS trong mạng nội bộ, đặt `secure_cookie => true` trong `config.php`.
- Chỉ mở ra Internet **duy nhất** `api/bank_webhook.php` (khi cần nhận webhook); các trang khác chỉ truy cập trong mạng bệnh viện.
- Nginx: chặn `location ~ ^/tckt/(inc|install|storage)/ { deny all; }` và `location = /tckt/config.php { deny all; }`.
- Sao lưu CSDL hằng ngày (`mysqldump tckt`).

## 6. Cấu trúc thư mục
```
inc/          thư viện: kết nối CSDL, phân quyền, VietQR, đọc số thành chữ, đọc/ghi Excel, nhập giá, đối soát, giá xăng
api/          endpoint AJAX (qr, prices, fuel_fetch) và webhook ngân hàng
assets/       CSS/JS của từng trang + thư viện đóng gói sẵn
install/      schema.sql, dữ liệu mẫu
storage/      file tạm khi nhập Excel, install.lock
```
