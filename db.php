<?php
require_once __DIR__ . '/config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// Lead update notes table
$conn->query("CREATE TABLE IF NOT EXISTS enquiry_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquiry_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Follow-up tasks table
$conn->query("CREATE TABLE IF NOT EXISTS enquiry_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enquiry_id INT NOT NULL,
    note TEXT NOT NULL,
    due_date DATE DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Daily notes table
$conn->query("CREATE TABLE IF NOT EXISTS daily_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Push subscriptions table
$conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_endpoint (endpoint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Main Enquiries Table
$conn->query("CREATE TABLE IF NOT EXISTS enquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL DEFAULT 'commercial',
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    location VARCHAR(255),
    address TEXT,
    area VARCHAR(100),
    slab VARCHAR(100),
    industry_usage VARCHAR(255),
    work_type VARCHAR(500),
    heavyload VARCHAR(50),
    timeline VARCHAR(100),
    epoxy_type VARCHAR(255),
    thickness VARCHAR(50),
    message TEXT,
    budget VARCHAR(100),
    concrete_grade VARCHAR(50),
    slab_age VARCHAR(100),
    cracks VARCHAR(255),
    contamination VARCHAR(100),
    previous_coating VARCHAR(100),
    industry_type VARCHAR(100),
    forklift VARCHAR(50),
    max_load VARCHAR(100),
    chemical_exposure VARCHAR(100),
    moisture_issue VARCHAR(50),
    water_washing VARCHAR(50),
    anti_skid VARCHAR(50),
    preferred_color VARCHAR(100),
    finish_type VARCHAR(100),
    line_marking VARCHAR(50),
    start_date DATE,
    urgent VARCHAR(50),
    working_hours VARCHAR(100),
    status VARCHAR(50) NOT NULL DEFAULT 'New',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
?>