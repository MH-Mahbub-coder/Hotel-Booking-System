-- ═══════════════════════════════════════════════
-- AURELIA GRAND HMS — MySQL Schema
-- Import this in phpMyAdmin → Import tab
-- ═══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS aurelia_grand
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE aurelia_grand;

CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(60)  NOT NULL,
  last_name  VARCHAR(60)  NOT NULL,
  email      VARCHAR(120) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  role       ENUM('guest','staff','admin') NOT NULL DEFAULT 'guest',
  phone      VARCHAR(30)  NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  token_hash CHAR(64)     NOT NULL UNIQUE,
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reset_user_expiry (user_id, expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS request_rate_limits (
  rate_key          CHAR(64) PRIMARY KEY,
  window_started_at DATETIME NOT NULL,
  attempts          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_rate_window (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(10)   NOT NULL UNIQUE,
  type        ENUM('Deluxe','Superior','Junior Suite','Grand Suite','Presidential') NOT NULL,
  floor       TINYINT       NOT NULL,
  status      ENUM('available','occupied','cleaning','maintenance') NOT NULL DEFAULT 'available',
  price       DECIMAL(10,2) NOT NULL,
  capacity    TINYINT       NOT NULL DEFAULT 2,
  amenities   VARCHAR(500)  NULL,
  image_url   VARCHAR(500)  NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bookings (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  booking_ref      VARCHAR(20)   NOT NULL UNIQUE,
  user_id          INT           NOT NULL,
  room_id          INT           NOT NULL,
  check_in         DATE          NOT NULL,
  check_out        DATE          NOT NULL,
  nights           TINYINT       NOT NULL,
  guests_count     TINYINT       NOT NULL DEFAULT 1,
  status           ENUM('pending','approved','confirmed','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'pending',
  total_amount     DECIMAL(10,2) NOT NULL,
  special_requests TEXT          NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (room_id) REFERENCES rooms(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_profiles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT         NOT NULL UNIQUE,
  department  ENUM('Housekeeper','Room Service','Receptionist','Maintenance','Chef','Concierge') NOT NULL,
  zone        VARCHAR(60) NOT NULL DEFAULT 'All Floors',
  shift_start TIME        NOT NULL,
  shift_end   TIME        NOT NULL,
  on_duty     TINYINT(1)  NOT NULL DEFAULT 1,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_orders (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  order_ref  VARCHAR(20)   NOT NULL UNIQUE,
  booking_id INT           NOT NULL,
  user_id    INT           NOT NULL,
  room_id    INT           NOT NULL,
  items      TEXT          NOT NULL,
  total      DECIMAL(10,2) NOT NULL,
  status     ENUM('pending','preparing','on-the-way','delivered','cancelled') NOT NULL DEFAULT 'pending',
  notes      TEXT          NULL,
  assigned_to INT          NULL,
  ordered_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (user_id)    REFERENCES users(id),
  FOREIGN KEY (room_id)    REFERENCES rooms(id),
  FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cleaning_requests (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  request_ref  VARCHAR(20) NOT NULL UNIQUE,
  room_id      INT         NOT NULL,
  requested_by INT         NOT NULL,
  assigned_to  INT         NULL,
  priority     ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status       ENUM('pending','in-progress','done','cancelled') NOT NULL DEFAULT 'pending',
  notes        TEXT        NULL,
  requested_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME    NULL,
  FOREIGN KEY (room_id)      REFERENCES rooms(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (assigned_to)  REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS chat_messages (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_email   VARCHAR(120) NOT NULL,
  user_name    VARCHAR(120) NOT NULL,
  message      TEXT         NOT NULL,
  message_type ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  is_read      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (user_email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reviews (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT      NOT NULL,
  booking_id  INT      NULL,
  rating      TINYINT  NOT NULL,
  comment     TEXT     NOT NULL,
  is_approved TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)    REFERENCES users(id),
  FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS invoices (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  invoice_ref     VARCHAR(20)   NOT NULL UNIQUE,
  booking_id      INT           NOT NULL UNIQUE,
  user_id         INT           NOT NULL,
  room_charges    DECIMAL(10,2) NOT NULL,
  service_charges DECIMAL(10,2) NOT NULL DEFAULT 0,
  tax_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_amount    DECIMAL(10,2) NOT NULL,
  status          ENUM('issued','paid','overdue') NOT NULL DEFAULT 'issued',
  issued_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         DATETIME      NULL,
  FOREIGN KEY (booking_id) REFERENCES bookings(id),
  FOREIGN KEY (user_id)    REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_tasks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(200) NOT NULL,
  description TEXT         NULL,
  assigned_to INT          NULL,
  created_by  INT          NOT NULL,
  priority    ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  status      ENUM('todo','in-progress','done','cancelled') NOT NULL DEFAULT 'todo',
  due_date    DATE         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (assigned_to) REFERENCES users(id),
  FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_menu_items (
  id         VARCHAR(60)  PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  unit_price DECIMAL(10,2) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO service_menu_items (id, name, unit_price) VALUES
('club-sandwich', 'Club Sandwich', 18.00),
('wagyu-burger', 'Wagyu Burger', 32.00),
('caesar-salad', 'Caesar Salad', 16.00),
('pasta-carbonara', 'Pasta Carbonara', 24.00),
('chocolate-fondant', 'Chocolate Fondant', 14.00),
('nespresso-selection', 'Nespresso Selection', 6.00),
('fresh-juice', 'Fresh Juice', 9.00),
('moet-chandon', 'Moet & Chandon', 95.00),
('craft-beer', 'Craft Beer', 12.00),
('extra-pillows-blanket', 'Extra Pillows & Blanket', 0.00),
('toiletry-kit', 'Toiletry Kit', 0.00),
('iron-ironing-board', 'Iron & Ironing Board', 0.00),
('turndown-service', 'Turndown Service', 15.00)
ON DUPLICATE KEY UPDATE name=VALUES(name), unit_price=VALUES(unit_price), is_active=1;

-- ── SEED ROOMS ──────────────────────────────────
INSERT IGNORE INTO rooms (room_number,type,floor,status,price,capacity,amenities,image_url) VALUES
('101','Deluxe',1,'available',150.00,2,'WiFi,TV,AC','https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=300&q=70'),
('102','Deluxe',1,'occupied',150.00,2,'WiFi,TV,AC','https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=300&q=70'),
('201','Superior',2,'cleaning',220.00,2,'WiFi,TV,AC,Bathtub','https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&q=70'),
('202','Superior',2,'available',220.00,2,'WiFi,TV,AC,Bathtub','https://images.unsplash.com/photo-1566665797739-1674de7a421a?w=300&q=70'),
('301','Junior Suite',3,'occupied',320.00,3,'WiFi,TV,AC,Lounge,Nespresso','https://images.unsplash.com/photo-1618773928121-c32242e63f39?w=300&q=70'),
('302','Junior Suite',3,'available',320.00,3,'WiFi,TV,AC,Lounge,Nespresso','https://images.unsplash.com/photo-1618773928121-c32242e63f39?w=300&q=70'),
('401','Grand Suite',4,'maintenance',480.00,4,'WiFi,TV,AC,Jacuzzi,Bar,Dining','https://images.unsplash.com/photo-1578683010236-d716f9a3f461?w=300&q=70'),
('501','Presidential',5,'available',780.00,4,'WiFi,TV,AC,Jacuzzi,Butler,Terrace,Bar','https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?w=300&q=70');

