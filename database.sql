-- ============================================================
--  MyStore ERP — MySQL schema
--  Run this in phpMyAdmin (Import tab) OR use install.php
-- ============================================================
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(120),
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('Super Admin','Manager','Accountant','Staff') NOT NULL DEFAULT 'Staff',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  contact VARCHAR(120),
  city VARCHAR(80),
  category VARCHAR(60),
  balance DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  sku VARCHAR(60),
  category VARCHAR(60),
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  price DECIMAL(12,2) NOT NULL DEFAULT 0,
  stock INT NOT NULL DEFAULT 0,
  low_stock INT NOT NULL DEFAULT 10,
  sold INT NOT NULL DEFAULT 0,
  supplier VARCHAR(140),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  phone VARCHAR(40),
  email VARCHAR(120),
  city VARCHAR(80),
  address VARCHAR(255),
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS couriers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  color VARCHAR(16) NOT NULL DEFAULT '#6366f1',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  order_date DATE NOT NULL,
  customer VARCHAR(140),
  phone VARCHAR(40),
  address VARCHAR(255),
  sales_person_id INT NULL,
  product_id INT NULL,
  qty INT NOT NULL DEFAULT 1,
  sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  cancel_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
  zone ENUM('inside','outside') NOT NULL DEFAULT 'inside',
  payment_type ENUM('cod','prepaid','bank_transfer','wallet') NOT NULL DEFAULT 'cod',
  payment_status ENUM('unpaid','paid','partial','refunded') NOT NULL DEFAULT 'unpaid',
  status ENUM('pending','processing','shipped','delivered','cancelled','returned') NOT NULL DEFAULT 'pending',
  courier_id INT NULL,
  remarks VARCHAR(255),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(order_date), INDEX(status), INDEX(courier_id), INDEX(product_id), INDEX(sales_person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  supplier VARCHAR(140),
  purchase_date DATE NOT NULL,
  items INT NOT NULL DEFAULT 0,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status ENUM('pending','confirmed','completed') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  description VARCHAR(255),
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency ENUM('Rs','USD') NOT NULL DEFAULT 'Rs',
  usd_amount DECIMAL(12,2) NULL,
  pay_from ENUM('cash','bank') NOT NULL DEFAULT 'cash',
  category VARCHAR(60) NOT NULL DEFAULT 'Other',
  product VARCHAR(140) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(expense_date), INDEX(category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(140) NOT NULL,
  role VARCHAR(80),
  department VARCHAR(60),
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  joined_date DATE NULL,
  status ENUM('active','on_leave','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  txn_date DATE NOT NULL,
  type ENUM('income','expense') NOT NULL,
  category VARCHAR(60),
  description VARCHAR(255),
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  user_name VARCHAR(120),
  action VARCHAR(255),
  module VARCHAR(60),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(60) PRIMARY KEY,
  svalue VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

-- ---- default settings ----
INSERT INTO settings (skey,svalue) VALUES
 ('store_name','Luprah Trading PVT.LTD'),('currency','Rs.'),('vat_percent','13'),
 ('delivery_inside','80'),('delivery_outside','150'),
 ('opening_cash','0'),('opening_bank','0'),('usd_rate','133'),
 ('vendor_id','39349'),('store_phone','9700195727, 9713228484'),
 ('store_email','luprahtrading789@gmail.com'),('help_line','01-5970736'),
 ('ncm_api_key','1a2d45ed6bd03aae39d236f065c988d17d9857b2')
ON DUPLICATE KEY UPDATE svalue=VALUES(svalue);

-- ---- starter couriers ----
INSERT INTO couriers (name,color) VALUES
 ('Self','#10b981'),('NCM','#8b5cf6'),('Gaau Besi','#f59e0b'),
 ('Pathao','#ef4444'),('Upaya','#3b82f6'),('Daraz','#f97316')
ON DUPLICATE KEY UPDATE color=VALUES(color);

-- ---- notifications ----
CREATE TABLE IF NOT EXISTS notifications(
  id INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(30) DEFAULT 'info',
  message VARCHAR(255) NOT NULL,
  link VARCHAR(120) DEFAULT '',
  is_read TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
