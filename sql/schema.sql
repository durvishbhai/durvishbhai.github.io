CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(20) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  sort_order INT NOT NULL DEFAULT 0,
  status TINYINT(1) NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  description TEXT,
  mrp DECIMAL(10,2) NOT NULL,
  sale_price DECIMAL(10,2) NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  image VARCHAR(255) DEFAULT NULL,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS addresses (
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,label VARCHAR(50),full_address TEXT NOT NULL,city VARCHAR(80) NOT NULL,state VARCHAR(80) NOT NULL,pincode VARCHAR(10) NOT NULL,is_default TINYINT(1) DEFAULT 0,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS orders (
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,address_id INT NOT NULL,subtotal DECIMAL(10,2) NOT NULL,delivery_charge DECIMAL(10,2) NOT NULL,total DECIMAL(10,2) NOT NULL,payment_method VARCHAR(30) NOT NULL,payment_status VARCHAR(30) NOT NULL,order_status VARCHAR(30) NOT NULL,
 shiprocket_order_id VARCHAR(50) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id), FOREIGN KEY (address_id) REFERENCES addresses(id)
);
CREATE TABLE IF NOT EXISTS order_items (
 id INT AUTO_INCREMENT PRIMARY KEY,order_id INT NOT NULL,product_id INT NOT NULL,product_name VARCHAR(140) NOT NULL,price DECIMAL(10,2) NOT NULL,qty INT NOT NULL,total DECIMAL(10,2) NOT NULL,
 FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS settings (
 `key` VARCHAR(80) PRIMARY KEY,
 `value` TEXT NOT NULL
);
INSERT INTO settings(`key`,`value`) VALUES
('delivery_enable','1'),('delivery_charge','25'),('free_delivery_min','499'),('min_order_value','99'),('cod_enabled','1'),('phonepe_enabled','1'),('demo_payment_enabled','1'),('shiprocket_enabled','0')
ON DUPLICATE KEY UPDATE value=VALUES(value);
INSERT INTO categories(name,slug,sort_order,status) VALUES ('Vegetables','vegetables',1,1),('Fruits','fruits',2,1),('Dairy','dairy',3,1) ON DUPLICATE KEY UPDATE name=VALUES(name);
