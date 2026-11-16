-- ==============================================================================
-- Supabase (PostgreSQL) Schema for Durables System
-- Run this script in the Supabase SQL Editor
-- ==============================================================================

-- 1. Sessions table (Essential for Vercel Serverless environment)
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    data TEXT NOT NULL,
    last_activity TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity);

-- 2. Users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    prefix VARCHAR(20) DEFAULT NULL,
    firstname VARCHAR(100) DEFAULT NULL,
    lastname VARCHAR(100) DEFAULT NULL,
    position VARCHAR(100) DEFAULT NULL,
    level VARCHAR(50) DEFAULT NULL,
    section VARCHAR(100) DEFAULT NULL,
    role VARCHAR(50) DEFAULT 'user',
    status VARCHAR(50) DEFAULT 'active',
    telegram_chat_id VARCHAR(100) DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 3. Supplies table
CREATE TABLE IF NOT EXISTS supplies (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) UNIQUE DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100) DEFAULT 'ทั่วไป',
    description TEXT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    qty INT DEFAULT 0,
    unit VARCHAR(50) DEFAULT NULL,
    min_stock INT DEFAULT 5,
    max_request INT DEFAULT 10,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    min_order INT DEFAULT 1,
    max_order INT DEFAULT 0,
    price NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    inspection_threshold INT DEFAULT 100
);

-- 4. Requests table
CREATE TABLE IF NOT EXISTS requests (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE SET NULL,
    section VARCHAR(255) DEFAULT NULL,
    request_name VARCHAR(100) DEFAULT NULL,
    request_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50) DEFAULT 'pending_head',
    last_approve VARCHAR(50) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    approver_head_id INT DEFAULT NULL,
    approver_head_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    approver_supply_id INT DEFAULT NULL,
    approver_supply_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    approver_director_id INT DEFAULT NULL,
    approver_director_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    reject_reason TEXT DEFAULT NULL,
    user_acknowledged BOOLEAN DEFAULT FALSE,
    user_acknowledged_date TIMESTAMP WITH TIME ZONE DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_requests_user_id ON requests(user_id);
CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status);

-- 5. Request Items table
CREATE TABLE IF NOT EXISTS request_items (
    id SERIAL PRIMARY KEY,
    request_id INT REFERENCES requests(id) ON DELETE CASCADE,
    supply_id INT REFERENCES supplies(id) ON DELETE CASCADE,
    qty INT DEFAULT NULL,
    original_qty INT DEFAULT NULL,
    approved_qty INT DEFAULT NULL,
    edit_reason TEXT DEFAULT NULL,
    is_deleted BOOLEAN DEFAULT FALSE
);
CREATE INDEX IF NOT EXISTS idx_request_items_req ON request_items(request_id);
CREATE INDEX IF NOT EXISTS idx_request_items_supply ON request_items(supply_id);

-- 6. Cart Items table
CREATE TABLE IF NOT EXISTS cart_items (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    supply_id INT NOT NULL REFERENCES supplies(id) ON DELETE CASCADE,
    qty INT NOT NULL DEFAULT 1,
    added_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_user_supply UNIQUE (user_id, supply_id)
);
CREATE INDEX IF NOT EXISTS idx_cart_items_user ON cart_items(user_id);
CREATE INDEX IF NOT EXISTS idx_cart_items_supply ON cart_items(supply_id);

-- 7. Procurements table
CREATE TABLE IF NOT EXISTS procurements (
    id SERIAL PRIMARY KEY,
    importer_id INT DEFAULT NULL,
    receipt_no VARCHAR(50) DEFAULT NULL,
    supplier_name VARCHAR(200) DEFAULT NULL,
    import_date DATE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 8. Procurement Items table
CREATE TABLE IF NOT EXISTS procurement_items (
    id SERIAL PRIMARY KEY,
    procurement_id INT REFERENCES procurements(id) ON DELETE CASCADE,
    supply_id INT REFERENCES supplies(id) ON DELETE CASCADE,
    qty INT DEFAULT NULL,
    unit_price NUMERIC(10,2) DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS idx_proc_items_proc ON procurement_items(procurement_id);
CREATE INDEX IF NOT EXISTS idx_proc_items_supply ON procurement_items(supply_id);

-- 9. Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT '',
    is_read BOOLEAN DEFAULT FALSE,
    type VARCHAR(50) DEFAULT 'info',
    related_id INT DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id);

-- 10. Delay False Login table
CREATE TABLE IF NOT EXISTS delay_false_login (
    id SERIAL PRIMARY KEY,
    ip_address VARCHAR(50) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempts INT DEFAULT 1,
    status BOOLEAN DEFAULT FALSE,
    last_attempt TIMESTAMP WITH TIME ZONE NOT NULL,
    CONSTRAINT ip_user UNIQUE (ip_address, username)
);

-- 11. System Logs table
CREATE TABLE IF NOT EXISTS system_logs (
    id SERIAL PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action_type VARCHAR(50) DEFAULT NULL,
    page_name VARCHAR(100) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    ip_address VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 12. System Settings table
CREATE TABLE IF NOT EXISTS system_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 13. Product Type table
CREATE TABLE IF NOT EXISTS product_type (
    id SERIAL PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL
);

-- 14. Type Volume (Units) table
CREATE TABLE IF NOT EXISTS type_volume (
    id SERIAL PRIMARY KEY,
    type_volume VARCHAR(255) NOT NULL
);

-- 15. Deleted Requests Log table
CREATE TABLE IF NOT EXISTS deleted_requests_log (
    id SERIAL PRIMARY KEY,
    request_id INT,
    request_name VARCHAR(100),
    last_approve VARCHAR(100) DEFAULT NULL,
    requester_name VARCHAR(255),
    requester_section VARCHAR(255),
    request_date TIMESTAMP WITH TIME ZONE,
    status_before VARCHAR(50),
    items_summary TEXT,
    deleted_by_id INT,
    deleted_by_name VARCHAR(255),
    delete_reason TEXT NOT NULL,
    deleted_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    restored_stock INT DEFAULT 0
);

-- 16. User Password Resets table
CREATE TABLE IF NOT EXISTS user_password_resets (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    temp_password VARCHAR(255) NOT NULL,
    reset_date DATE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    is_active INT DEFAULT 1
);
