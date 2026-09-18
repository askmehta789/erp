-- ============================================================
--  MyStore ERP — Database Repair Script
--  Run this in cPanel → phpMyAdmin → select your database
--  → SQL tab → paste this → click Go
--  Safe to run multiple times. Does NOT delete any data.
-- ============================================================

-- orders: add missing columns
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS address         VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS cost_price      DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS cancel_charge   DECIMAL(12,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS zone            ENUM('inside','outside') NOT NULL DEFAULT 'inside',
  ADD COLUMN IF NOT EXISTS payment_status  ENUM('unpaid','paid','partial','refunded') NOT NULL DEFAULT 'unpaid',
  ADD COLUMN IF NOT EXISTS courier_id      INT NULL,
  ADD COLUMN IF NOT EXISTS remarks         VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS created_at      DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- expenses: add missing columns
ALTER TABLE expenses
  ADD COLUMN IF NOT EXISTS currency    ENUM('Rs','USD') NOT NULL DEFAULT 'Rs',
  ADD COLUMN IF NOT EXISTS usd_amount  DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS product     VARCHAR(140) NULL,
  ADD COLUMN IF NOT EXISTS pay_from    ENUM('cash','bank') NOT NULL DEFAULT 'cash';

-- products: add missing columns
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS low_stock  INT NOT NULL DEFAULT 10,
  ADD COLUMN IF NOT EXISTS sold       INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS supplier   VARCHAR(140) NULL;

-- customers: add missing columns
ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS address  VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS city     VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS status   ENUM('active','inactive') NOT NULL DEFAULT 'active';

-- suppliers: add missing column
ALTER TABLE suppliers
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- couriers: add missing column
ALTER TABLE couriers
  ADD COLUMN IF NOT EXISTS created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;

-- make sure settings rows exist
INSERT INTO settings (skey,svalue) VALUES
  ('store_name','MyStore ERP'),('currency','Rs.'),('vat_percent','13'),
  ('delivery_inside','80'),('delivery_outside','150'),
  ('opening_cash','0'),('opening_bank','0'),('usd_rate','133')
ON DUPLICATE KEY UPDATE svalue=VALUES(svalue);

-- make sure starter couriers exist
INSERT IGNORE INTO couriers (name,color) VALUES
  ('Self','#10b981'),('NCM','#8b5cf6'),('Gaau Besi','#f59e0b'),
  ('Pathao','#ef4444'),('Upaya','#3b82f6'),('Daraz','#f97316');

SELECT 'Repair complete — all columns added.' AS result;
