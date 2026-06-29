<?php
require_once __DIR__ . '/bootstrap.php';

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=leads.csv");

$output = fopen("php://output", "w");

// Column headers
fputcsv($output, ['ID', 'Name', 'Phone', 'Location', 'Usage', 'Status', 'Date']);

$stmt = $conn->prepare("SELECT * FROM enquiries WHERE company_id = ?");
$stmt->bind_param('i', $companyId);
$stmt->execute();
$result = $stmt->get_result();

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