-- Lizenzverwaltungssystem: Datenbank-Schema
-- Erstellt für Lite-Version auf Shared Hosting

-- Kunden-Tabelle
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  company VARCHAR(100),
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Produkte-Tabelle
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL UNIQUE,
  description TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Lizenzpläne-Tabelle
CREATE TABLE license_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  duration INT NOT NULL COMMENT 'In Tagen, 0 für unbegrenzt',
  max_domains INT DEFAULT 1,
  price DECIMAL(10,2) NOT NULL,
  features TEXT COMMENT 'JSON-encodierte Features',
  FOREIGN KEY (product_id) REFERENCES products(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Lizenzen-Tabelle
CREATE TABLE licenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  license_key VARCHAR(36) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  plan_id INT NOT NULL,
  start_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  end_date TIMESTAMP NULL COMMENT 'NULL für unbegrenzt',
  status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
  validation_count INT DEFAULT 0,
  last_validation TIMESTAMP NULL,
  expiry_notified TINYINT(1) DEFAULT 0,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (plan_id) REFERENCES license_plans(id),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Lizenzdomains-Tabelle
CREATE TABLE license_domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  license_id INT NOT NULL,
  domain VARCHAR(255) NOT NULL,
  verified BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
  UNIQUE KEY (license_id, domain)
);

-- Validierungslogs-Tabelle
CREATE TABLE validation_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  license_id INT NOT NULL,
  domain VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT,
  is_valid BOOLEAN DEFAULT FALSE,
  message VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
);

-- Admin-Benutzer-Tabelle
CREATE TABLE admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(100) NOT NULL UNIQUE,
  last_login TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Hinzufügen von Index für die Performance-Optimierung
ALTER TABLE licenses ADD INDEX idx_license_key (license_key);
ALTER TABLE licenses ADD INDEX idx_customer_id (customer_id);
ALTER TABLE licenses ADD INDEX idx_plan_id (plan_id);
ALTER TABLE licenses ADD INDEX idx_status (status);
ALTER TABLE licenses ADD INDEX idx_end_date (end_date);
ALTER TABLE license_domains ADD INDEX idx_domain (domain);
ALTER TABLE validation_logs ADD INDEX idx_created_at (created_at);

-- Standard-Admin-Benutzer erstellen (Passwort: admin123)
INSERT INTO admin_users (username, password, email) 
VALUES ('admin', '$2y$10$5Oy5XGCDcbEX32lxZXfV0eOmn15NohoEy.F.hGqgfoWDPJ7ShnoGG', 'admin@example.com');

-- Beispielprodukt hinzufügen
INSERT INTO products (name, slug, description) 
VALUES ('Web-Tool Pro', 'web-tool-pro', 'Professionelles Webtool für Entwickler');

-- Beispiel-Lizenzplan hinzufügen
INSERT INTO license_plans (product_id, name, duration, max_domains, price, features) 
VALUES (1, 'Standard', 365, 3, 49.99, '["basic_feature", "support"]');