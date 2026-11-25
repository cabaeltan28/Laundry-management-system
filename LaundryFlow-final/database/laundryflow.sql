-- =====================================================================
--  LaundryFlow - Laundry Order Monitoring & Business Transparency System
--  Database schema + starter data (MySQL 5.7+ / MariaDB 10.3+ / XAMPP)
--
--  HOW TO IMPORT (XAMPP):
--    1. Start Apache and MySQL in the XAMPP Control Panel.
--    2. Open http://localhost/phpmyadmin
--    3. Click "Import", choose this file, click "Go".
--       (This file creates the `laundryflow` database by itself.)
--
--  Default accounts (CHANGE THESE after first login):
--    Owner     : owner    / owner123
--    Employee  : jessa    / staff123   (PIN 1234)
--    Employee  : carlo    / staff123   (PIN 5678)
--    Customer  : angela   / customer123
--  (jessa, carlo, angela and the sample customers are DEMO accounts:
--   Owner > Settings > Delete demo data removes them.)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS laundryflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE laundryflow;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS cash_closings;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS discrepancies;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- users: everyone who can log in (owner, employee, customer)
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role ENUM('owner','employee','customer') NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NULL,
  lang VARCHAR(5) NOT NULL DEFAULT 'en',
  theme VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- customers: walk-in records; user_id is set when linked to an account
-- ---------------------------------------------------------------------
CREATE TABLE customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NULL,
  email VARCHAR(150) NULL,
  address VARCHAR(255) NULL,
  notes VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_customers_phone (phone),
  KEY idx_customers_user (user_id),
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- services: laundry services and pricing
-- unit 'kg' = priced per kilo, 'piece' = priced per item
-- base_hours = normal turnaround, min_qty = minimum billable quantity
-- ---------------------------------------------------------------------
CREATE TABLE services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  unit ENUM('kg','piece') NOT NULL DEFAULT 'kg',
  price DECIMAL(10,2) NOT NULL,
  min_qty DECIMAL(10,2) NOT NULL DEFAULT 0,
  base_hours DECIMAL(5,2) NOT NULL DEFAULT 6,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- orders
-- ---------------------------------------------------------------------
CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(20) NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  weight DECIMAL(10,2) NOT NULL DEFAULT 0,
  initial_weight DECIMAL(10,2) NOT NULL DEFAULT 0,
  bag_count INT NOT NULL DEFAULT 1,
  is_rush TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  rush_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_note VARCHAR(100) NULL,
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
  payment_status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  status ENUM('received','weighed','queued','washing','drying','folding','ready','claimed','cancelled') NOT NULL DEFAULT 'received',
  estimated_ready_at DATETIME NULL,
  ready_at DATETIME NULL,
  claimed_at DATETIME NULL,
  claimed_by INT UNSIGNED NULL,
  tracking_token VARCHAR(16) NOT NULL,
  pickup_code VARCHAR(6) NOT NULL,
  reminder_count INT NOT NULL DEFAULT 0,
  last_reminder_at DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_orders_no (order_no),
  KEY idx_orders_status (status),
  KEY idx_orders_customer (customer_id),
  KEY idx_orders_created (created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_orders_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE order_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL,
  changed_by INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_hist_order (order_id),
  CONSTRAINT fk_hist_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- payments: never deleted, only voided (with reason)
-- ---------------------------------------------------------------------
CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','gcash','maya','bank','other') NOT NULL DEFAULT 'cash',
  reference_no VARCHAR(60) NULL,
  received_by INT UNSIGNED NULL,
  is_void TINYINT(1) NOT NULL DEFAULT 0,
  void_reason VARCHAR(255) NULL,
  voided_by INT UNSIGNED NULL,
  voided_at DATETIME NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_pay_order (order_id),
  KEY idx_pay_created (created_at),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- discrepancies: suspicious changes waiting for owner review
-- ---------------------------------------------------------------------
CREATE TABLE discrepancies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  old_value VARCHAR(100) NULL,
  new_value VARCHAR(100) NULL,
  difference VARCHAR(50) NULL,
  percent_change DECIMAL(7,2) NULL,
  reason VARCHAR(255) NULL,
  reported_by INT UNSIGNED NULL,
  status ENUM('open','approved','flagged') NOT NULL DEFAULT 'open',
  reviewed_by INT UNSIGNED NULL,
  review_note VARCHAR(255) NULL,
  reviewed_at DATETIME NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_disc_status (status),
  KEY idx_disc_order (order_id),
  CONSTRAINT fk_disc_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- audit_logs: append-only change history (protected by triggers below)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(30) NOT NULL,
  entity_id INT UNSIGNED NULL,
  order_id INT UNSIGNED NULL,
  field VARCHAR(40) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  reason VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_audit_order (order_id),
  KEY idx_audit_actor (actor_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  order_id INT UNSIGNED NULL,
  type VARCHAR(30) NOT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(255) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'in_app',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_notif_user (user_id),
  KEY idx_notif_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settings (
  skey VARCHAR(50) NOT NULL PRIMARY KEY,
  svalue VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- expenses: detergent, electricity, rent... (for profit reports)
-- ---------------------------------------------------------------------
CREATE TABLE expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  expense_date DATE NOT NULL,
  category VARCHAR(40) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_exp_date (expense_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- cash_closings: end-of-shift cash count vs. what the system expects
-- ---------------------------------------------------------------------
CREATE TABLE cash_closings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_start DATETIME NOT NULL,
  period_end DATETIME NOT NULL,
  expected_cash DECIMAL(10,2) NOT NULL,
  counted_cash DECIMAL(10,2) NOT NULL,
  difference DECIMAL(10,2) NOT NULL,
  payment_count INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  closed_by INT UNSIGNED NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_login_ident (identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- STARTER DATA
-- =====================================================================
INSERT INTO users (id, role, full_name, username, email, phone, password_hash, pin_hash, is_active, created_at, lang, is_demo) VALUES
(1, 'owner',    'Miguel Reyes',  'owner',  'owner@laundryflow.local', '09170000001', '$2y$10$MAYLxOsAKlC/S2Njo3d3oeRwXGDaJB1BQoFUDUmDI6jPoBvF2CVem', NULL, 1, '2026-08-17 08:00:00', 'en', 0),
(2, 'employee', 'Jessa Mendoza', 'jessa',  NULL, '09170000002', '$2y$10$/idjlad8CUdKUlM6m9UwvujcX8w6TqzEYhUy7Q4pWqF6Vw9TNCkXO', '$2y$10$mNMNTGa6Dzy5QwVIdgKdZOFOy6Vr1rSjJ9ztpf0MhcDP6gKrP3Qra', 1, '2026-08-17 08:00:00', 'tl', 1),
(3, 'employee', 'Carlo Ramos',   'carlo',  NULL, '09170000003', '$2y$10$/idjlad8CUdKUlM6m9UwvujcX8w6TqzEYhUy7Q4pWqF6Vw9TNCkXO', '$2y$10$lTFV1m54l7FgAQHEVNidHO/.hDLK6KZIlhouF7E2LvW.J9g2xE9dK', 1, '2026-08-17 08:00:00', 'tl', 1),
(4, 'customer', 'Angela Santos', 'angela', 'angela@example.com', '09174821056', '$2y$10$hXrBCFHLtnyepBHOrKxwqejn78w6CsOLejDVEc9ZJenKMZgdWLRVG', NULL, 1, '2026-08-17 08:00:00', 'en', 1);

INSERT INTO customers (id, user_id, full_name, phone, email, address, created_by, created_at, is_demo) VALUES
(1, 4,    'Angela Santos', '09174821056', 'angela@example.com', 'Poblacion', 2, '2026-08-17 08:00:00', 1),
(2, NULL, 'Paolo Lim',     '09181234567', NULL, NULL, 2, '2026-08-17 08:00:00', 1),
(3, NULL, 'Bea Navarro',   '09201112233', NULL, NULL, 2, '2026-08-17 08:00:00', 1);

INSERT INTO services (id, name, description, unit, price, min_qty, base_hours, is_active, sort_order, created_at) VALUES
(1, 'Wash, dry & fold', 'Full service: washed, dried and folded', 'kg',    70.00, 3, 6, 1, 1, '2026-08-17 08:00:00'),
(2, 'Wash & fold',      'Washed and folded, air-dry items excluded', 'kg', 60.00, 3, 5, 1, 2, '2026-08-17 08:00:00'),
(3, 'Wash only',        'Self-fold, washed and dried only',        'kg',    45.00, 3, 4, 1, 3, '2026-08-17 08:00:00'),
(4, 'Dry clean',        'Delicates, barong, gowns, suits',         'piece', 180.00, 1, 48, 1, 4, '2026-08-17 08:00:00'),
(5, 'Comforter',        'Comforter / blanket, any size',           'piece', 380.00, 1, 24, 1, 5, '2026-08-17 08:00:00');

INSERT INTO settings (skey, svalue) VALUES
('shop_name', 'LaundryFlow'),
('shop_branch', 'Poblacion Branch'),
('shop_address', '2149 Don Pedro St., Poblacion'),
('shop_phone', '0917 000 0001'),
('open_hour', '8'),
('close_hour', '20'),
('rush_percent', '25'),
('rush_speed_factor', '0.5'),
('capacity_kg_per_hour', '15'),
('piece_kg_equivalent', '3'),
('reminder_after_hours', '24'),
('max_reminders', '3'),
('discrepancy_threshold_percent', '10'),
('last_reminder_run', ''),
('theme', 'pink'),
('announcement', ''),
('loyalty_every', '10'),
('loyalty_discount_percent', '10'),
('staff_default_lang', 'tl'),
('ai_insight_date', ''),
('ai_insight_text', ''),
('allow_user_theme', '1'),
('custom_color', '#db2777');

-- =====================================================================
-- TRIGGERS: make audit_logs append-only (cannot be edited or deleted).
-- Only DEMO rows (is_demo = 1) may be deleted, so demo data can be removed.
-- In phpMyAdmin these import fine. If your tool complains about the
-- delimiter, you may skip this section - the app still works.
-- =====================================================================
DELIMITER $$
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
END$$
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW BEGIN
  IF OLD.is_demo = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
  END IF;
END$$
DELIMITER ;
