<?php
require_once 'auth.php';
require_login();
require 'db.php';

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=leads.csv");

$output = fopen("php://output", "w");

// Column headers
fputcsv($output, ['ID', 'Name', 'Phone', 'Location', 'Usage', 'Status', 'Date']);

$result = $conn->query("SELECT * FROM enquiries");

while($row = $result->fetch_assoc()){
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['phone'],
        $row['location'],
        $row['industry_usage'],
        $row['status'],
        $row['created_at']
    ]);
}

fclose($output);
exit;
?>