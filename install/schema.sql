-- Công cụ nội bộ Phòng TCKT - Bệnh viện HNĐK Nghệ An
-- MySQL 5.7+ / MariaDB 10.3+, utf8mb4

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(150) NOT NULL,
  role ENUM('admin','mod','user') NOT NULL DEFAULT 'user',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tài khoản nhận tiền QR
CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bank_bin VARCHAR(6) NOT NULL,
  bank_name VARCHAR(100) NOT NULL,
  account_no VARCHAR(19) NOT NULL,
  account_name VARCHAR(100) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Danh mục cơ sở khám chữa bệnh
CREATE TABLE IF NOT EXISTS facilities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NULL,
  name VARCHAR(255) NOT NULL,
  address VARCHAR(255) NULL,
  distance_km DECIMAL(8,1) NULL COMMENT 'Khoảng cách mặc định từ bệnh viện',
  is_home TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Là bệnh viện mình',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fac_name (name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lịch sử giá xăng
CREATE TABLE IF NOT EXISTS fuel_prices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fuel_name VARCHAR(100) NOT NULL,
  price INT UNSIGNED NOT NULL,
  effective_at DATETIME NOT NULL,
  source VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fuel_eff (effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Danh mục giá dịch vụ
CREATE TABLE IF NOT EXISTS price_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('bhyt','yeucau','khac') NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category ENUM('bhyt','yeucau','khac') NOT NULL,
  equiv_code VARCHAR(50) NULL COMMENT 'Mã tương đương',
  tech_code VARCHAR(50) NULL COMMENT 'Mã kỹ thuật',
  name VARCHAR(500) NOT NULL,
  name_search VARCHAR(500) NOT NULL COMMENT 'Tên không dấu, chữ thường để tìm kiếm',
  unit VARCHAR(50) NULL,
  price DECIMAL(15,0) NOT NULL,
  decision_name VARCHAR(255) NULL COMMENT 'Quyết định ban hành giá',
  decision_date DATE NULL COMMENT 'Ngày ban hành',
  effective_from DATE NOT NULL COMMENT 'Ngày áp dụng',
  effective_to DATE NULL COMMENT 'Hết hiệu lực từ ngày (NULL = còn hiệu lực)',
  note VARCHAR(255) NULL,
  batch_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_price_cat_eff (category, effective_from, effective_to),
  KEY idx_price_code (category, equiv_code),
  KEY idx_price_tech (category, tech_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Yêu cầu thanh toán QR
CREATE TABLE IF NOT EXISTS payment_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  treatment_code VARCHAR(30) NOT NULL,
  patient_name VARCHAR(150) NULL,
  amount DECIMAL(15,0) NOT NULL,
  transfer_content VARCHAR(50) NOT NULL,
  bank_account_id INT UNSIGNED NOT NULL,
  status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  bank_txn_id INT UNSIGNED NULL,
  confirm_note VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pr_status (status, created_at),
  KEY idx_pr_treat (treatment_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Giao dịch tiền vào nhận từ ngân hàng (webhook / sao kê)
CREATE TABLE IF NOT EXISTS bank_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source ENUM('webhook','statement','demo') NOT NULL,
  ref_no VARCHAR(100) NOT NULL,
  account_no VARCHAR(30) NULL,
  txn_time DATETIME NOT NULL,
  amount DECIMAL(15,0) NOT NULL,
  content VARCHAR(500) NULL,
  raw_data TEXT NULL,
  payment_request_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_txn_ref (source, ref_no),
  KEY idx_txn_match (payment_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phiếu chi hỗ trợ vận chuyển
CREATE TABLE IF NOT EXISTS transport_vouchers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_year SMALLINT UNSIGNED NOT NULL,
  voucher_seq INT UNSIGNED NOT NULL,
  voucher_no VARCHAR(30) NOT NULL,
  patient_name VARCHAR(150) NOT NULL,
  treatment_code VARCHAR(30) NULL,
  insurance_no VARCHAR(20) NULL COMMENT 'Số thẻ BHYT',
  patient_address VARCHAR(255) NULL,
  receiver_name VARCHAR(150) NULL COMMENT 'Người nhận tiền (nếu khác người bệnh)',
  from_facility_id INT UNSIGNED NULL,
  from_facility VARCHAR(255) NOT NULL,
  to_facility_id INT UNSIGNED NULL,
  to_facility VARCHAR(255) NOT NULL,
  distance_km DECIMAL(8,1) NOT NULL,
  fuel_price INT UNSIGNED NOT NULL,
  liters_per_km DECIMAL(5,3) NOT NULL DEFAULT 0.200,
  amount DECIMAL(15,0) NOT NULL,
  amount_words VARCHAR(255) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  cancel_reason VARCHAR(255) NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  print_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_voucher_seq (voucher_year, voucher_seq),
  KEY idx_voucher_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nhật ký thao tác
CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  detail VARCHAR(1000) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_time (created_at),
  KEY idx_audit_action (action, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
