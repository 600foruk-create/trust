-- Madrasa Accounts Database Schema
-- Compatible with Hostinger MySQL (u245697138_madrasa)

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(100) NOT NULL UNIQUE,
  label VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(190) NULL UNIQUE,
  phone_number VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id BIGINT UNSIGNED NOT NULL,
  permission_code VARCHAR(80) NOT NULL,
  PRIMARY KEY (user_id, permission_code),
  INDEX idx_up_user (user_id),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_funds (
  user_id BIGINT UNSIGNED NOT NULL,
  fund_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, fund_id),
  INDEX idx_uf_user (user_id),
  INDEX idx_uf_fund (fund_id),
  CONSTRAINT fk_uf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uf_fund FOREIGN KEY (fund_id) REFERENCES funds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS funds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  fund_code VARCHAR(30) NOT NULL UNIQUE,
  description TEXT NULL,
  is_restricted TINYINT(1) NOT NULL DEFAULT 0,
  scope ENUM('all','madrasa','trust') NOT NULL DEFAULT 'all',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_code VARCHAR(30) NOT NULL UNIQUE,
  account_name VARCHAR(120) NOT NULL,
  account_type ENUM('asset','liability','fund_balance','income','expense') NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  is_cash_or_bank TINYINT(1) NOT NULL DEFAULT 0,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  scope ENUM('all','madrasa','trust') NOT NULL DEFAULT 'all',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_accounts_parent FOREIGN KEY (parent_id) REFERENCES accounts(id),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_account_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS donors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  donor_name VARCHAR(150) NOT NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  notes TEXT NULL,
  fund_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_donor_name (donor_name),
  INDEX idx_donor_phone (phone),
  INDEX idx_donor_fund (fund_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(30) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  designation VARCHAR(100) NULL,
  phone VARCHAR(30) NULL,
  joining_date DATE NULL,
  monthly_salary DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaction_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_name VARCHAR(100) NOT NULL,
  category_type ENUM('income','expense') NOT NULL,
  account_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_category_type_name (category_type, category_name),
  CONSTRAINT fk_category_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vouchers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_no VARCHAR(40) NOT NULL UNIQUE,
  voucher_type ENUM('income','expense','transfer','journal','opening') NOT NULL,
  voucher_date DATE NOT NULL,
  fund_id BIGINT UNSIGNED NULL,
  task_id BIGINT UNSIGNED NULL,
  donor_id BIGINT UNSIGNED NULL,
  payee_name VARCHAR(150) NULL,
  category_id BIGINT UNSIGNED NULL,
  payment_method ENUM('cash','bank','online','cheque','other') NULL,
  reference_no VARCHAR(100) NULL,
  description TEXT NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  posted_at DATETIME NULL,
  CONSTRAINT fk_voucher_fund FOREIGN KEY (fund_id) REFERENCES funds(id),
  CONSTRAINT fk_voucher_donor FOREIGN KEY (donor_id) REFERENCES donors(id),
  CONSTRAINT fk_voucher_category FOREIGN KEY (category_id) REFERENCES transaction_categories(id),
  CONSTRAINT fk_voucher_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_voucher_date_type (voucher_date, voucher_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id BIGINT UNSIGNED NULL,
  entry_date DATE NOT NULL,
  narration VARCHAR(255) NOT NULL,
  status ENUM('draft','posted','reversed') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_journal_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
  CONSTRAINT fk_journal_user FOREIGN KEY (created_by) REFERENCES users(id),
  INDEX idx_journal_date (entry_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  journal_entry_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  fund_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT fk_line_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_line_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_line_fund FOREIGN KEY (fund_id) REFERENCES funds(id),
  INDEX idx_journal_line_account (account_id),
  INDEX idx_journal_line_fund (fund_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salary_records (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  salary_month DATE NOT NULL COMMENT 'Use first day of salary month',
  basic_salary DECIMAL(14,2) NOT NULL,
  additions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  net_salary DECIMAL(14,2) NOT NULL,
  status ENUM('unpaid','partially_paid','paid') NOT NULL DEFAULT 'unpaid',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employee_salary_month (employee_id, salary_month),
  CONSTRAINT fk_salary_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salary_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  salary_record_id BIGINT UNSIGNED NOT NULL,
  voucher_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_date DATE NOT NULL,
  CONSTRAINT fk_salary_payment_record FOREIGN KEY (salary_record_id) REFERENCES salary_records(id),
  CONSTRAINT fk_salary_payment_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
  INDEX idx_salary_payment_date (payment_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id BIGINT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attachment_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
  CONSTRAINT fk_attachment_user FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id BIGINT UNSIGNED NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_code VARCHAR(30) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  project_location VARCHAR(255) NULL,
  target_lat DECIMAL(10, 8) NULL,
  target_lng DECIMAL(11, 8) NULL,
  radius_meters INT UNSIGNED NOT NULL DEFAULT 300,
  before_photo VARCHAR(255) NULL,
  after_photo VARCHAR(255) NULL,
  assigned_to BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  status ENUM('pending','in_progress','on_hold','completed','cancelled') NOT NULL DEFAULT 'pending',
  progress_percent INT UNSIGNED NOT NULL DEFAULT 0,
  estimated_budget DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  start_date DATE NULL,
  due_date DATE NULL,
  fund_id BIGINT UNSIGNED NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_task_assigned (assigned_to),
  INDEX idx_task_status (status),
  INDEX idx_task_priority (priority),
  INDEX idx_task_fund (fund_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  progress_percent INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('pending','in_progress','on_hold','completed','cancelled') NULL,
  report_text TEXT NOT NULL,
  photo_attachment VARCHAR(255) NULL,
  latitude DECIMAL(10, 8) NULL,
  longitude DECIMAL(11, 8) NULL,
  location_accuracy DECIMAL(8, 2) NULL,
  location_address VARCHAR(255) NULL,
  is_onsite TINYINT(1) NULL,
  distance_meters INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tlog_task (task_id),
  INDEX idx_tlog_user (user_id),
  INDEX idx_tlog_onsite (is_onsite),
  CONSTRAINT fk_tlog_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  link_url VARCHAR(255) NULL,
  type ENUM('info', 'warning', 'success', 'task') NOT NULL DEFAULT 'task',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_user (user_id, is_read),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (name, description) VALUES
('Super Admin','Full system access & Final Approvals (مکمل اختیارات)'),
('Approver / Checker','Review and approve pending vouchers, audit receipts (واؤچرز منظوری و پڑتال)'),
('User / Cashier','Record income and expenses - Pending approval (وصولی و اخراجات کا اندراج)'),
('Viewer','Read-only access (صرف دیکھنے کی اجازت)');

-- Cleanup legacy duplicate 'Cashier' role if present
DELETE FROM roles WHERE name = 'Cashier';

INSERT IGNORE INTO permissions (code,label) VALUES
('dashboard.view','View dashboard'),('users.view','View users'),('users.manage','Manage users'),
('income.view','View income'),('income.create','Create income'),('expense.view','View expenses'),('expense.create','Create expenses'),
('approvals.manage','Approve and reject vouchers'),
('reports.view','View reports'),('settings.manage','Manage settings'),('accounting.view','View accounting');

-- Initial permission assignments. Super Admin gets all permissions.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name='Super Admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Approver / Checker' AND p.code <> 'settings.manage';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='User / Cashier' AND p.code IN ('dashboard.view','income.view','income.create','expense.view','expense.create','reports.view');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Viewer' AND p.code IN ('dashboard.view','income.view','expense.view','reports.view','accounting.view');

INSERT IGNORE INTO accounts (account_code,account_name,account_type,is_cash_or_bank) VALUES
('1000','Cash in Hand (نقدی کیش)','asset',1),
('1010','Bank Account (بینک اکاؤنٹ)','asset',1),
('3000','Opening Fund Balance (افتتاحی فنڈ بیلنس)','fund_balance',0),
('4000','عام عطیات (General Donations)','income',0),
('4010','زکوٰۃ فنڈ (Zakat Receipts)','income',0),
('4020','صدقات و خیرات (Sadqat & Charity)','income',0),
('4030','فطرانہ و فدیہ (Fitrana & Fidya)','income',0),
('4040','تعلیمی فیس و داخلہ (Tuition / Admission Fees)','income',0),
('4050','ماہانہ کفالت طلبہ (Monthly Student Sponsorship)','income',0),
('4060','کتب و مکتبہ آمدنی (Books & Stationery Sale)','income',0),
('4070','قربانی کی کھالیں (Qurbani Hides & Skin)','income',0),
('4080','تعمیراتی چندہ (Construction Donations)','income',0),
('4090','متفرق آمدنی (Miscellaneous Income)','income',0),
('5000','عمومی و متفرق اخراجات (General Expenses)','expense',0),
('5010','اساتذہ و عملہ کی تنخواہیں (Teachers & Staff Salaries)','expense',0),
('5020','طلبہ کا کھانا و راشن (Student Food & Mess / Kitchen)','expense',0),
('5030','بجلی، گیس، پانی کے بلات (Electricity, Gas, Water)', 'expense',0),
('5040','کتب، کاپیاں و تعلیمی سامان (Books & Stationery)','expense',0),
('5050','طلبہ کا علاج و وظائف (Student Medical & Stipends)','expense',0),
('5060','عمارت کی مرمت و رنگ و روغن (Building Maintenance)','expense',0),
('5070','سفر و ٹرانسپورٹیشن (Travel & Transportation)','expense',0),
('4100','ماہانہ کفالت عطیات (Monthly Sponsorship)','income',0),
('4110','راشن و فوڈ ایڈ عطیات (Ration & Food Aid)','income',0),
('4120','میڈیکل و ایمبولینس عطیات (Medical & Ambulance)','income',0),
('4130','واٹر پروجیکٹ عطیات (Clean Water Donations)','income',0),
('4140','یتیم و بیوہ کفالت عطیات (Orphan & Widow Aid)','income',0),
('4150','ہنگامی ریلیف عطیات (Disaster Relief Donations)','income',0),
('5100','راشن خریداری و تقسیم (Ration Purchases & Distribution)','expense',0),
('5110','مریضوں کا علاج و ادویات (Patient Medical Aid & Medicine)','expense',0),
('5120','ایمبولینس ایندھن و مرمت (Ambulance Fuel & Maintenance)','expense',0),
('5130','بیوہ و یتیم ماہانہ وظائف (Widow & Orphan Monthly Stipends)','expense',0),
('5140','واٹر پروجیکٹ و ہینڈ پمپ اخراجات (Water Project & Handpump Costs)','expense',0),
('5150','ہنگامی ریلیف و امدادی سامان (Emergency Relief & Supplies)','expense',0);

INSERT IGNORE INTO funds (name,fund_code,description,is_restricted) VALUES
('Trust General Fund','TGF','General trust administrative and operational expenses',0),
('Zakat & Sadaqah Fund','ZAK','Zakat & Sadaqah contributions; strictly for eligible beneficiaries',1),
('Ration & Food Aid Fund','RAF','Monthly ration packages and food support for deserving families',1),
('Medical & Ambulance Fund','MAF','Medical treatments, medicines and ambulance services',1),
('Clean Water Project Fund','CWP','Water handpumps, wells and filtration plant projects',1),
('Orphan & Widow Support Fund','OWF','Monthly financial stipend and educational care for orphans and widows',1),
('Emergency & Disaster Relief','EDF','Emergency relief fund for floods, earthquakes and crisis',1);

INSERT INTO settings (setting_key,setting_value) VALUES
('madrasa_name','Trust Accounts'),
('org_logo',''),
('madrasa_phone',''),
('madrasa_address',''),
('currency','PKR'),
('timezone','Asia/Karachi'),
('financial_year_start','07-01'),
('ui_language','bilingual'),
('org_type','trust')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
