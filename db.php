<?php
$host = "127.0.0.1";
$port = 3307;
$dbname = "coatcraft_enquiry";
$username = "root";
$password = "";

$conn = new mysqli($host, $username, $password, $dbname, $port);

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
?>