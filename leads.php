<?php
// Shared, tenant-scoped lead query helpers used by crm-dashboard.php and
// view-leads.php, so the same SQL isn't duplicated in both files.
// Every function here requires $companyId and always filters by it.

function getLeadStatusCounts(mysqli $conn, int $companyId): array {
    $counts = [];
    $statusQueries = [
        'total' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=?",
        'new' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='New'",
        'contacted' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Contacted'",
        'closed' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Closed'",
        'not_interested' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Not Interested'",
        'work_done' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND status='Work Done'",
        'industrial' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND type='commercial'",
        'residential' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND type='residential'",
        'weekly' => "SELECT COUNT(*) c FROM enquiries WHERE company_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        'open_followups' => "SELECT COUNT(*) c FROM enquiry_followups WHERE company_id=? AND status='Open'",
        'overdue_followups' => "SELECT COUNT(*) c FROM enquiry_followups WHERE company_id=? AND status='Open' AND due_date < CURDATE() AND due_date IS NOT NULL",
        'due_soon_followups' => "SELECT COUNT(*) c FROM enquiry_followups WHERE company_id=? AND status='Open' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)",
    ];
    foreach ($statusQueries as $key => $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $counts[$key] = (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
    }
    return $counts;
}

function getLeadById(mysqli $conn, int $companyId, int $leadId): ?array {
    $stmt = $conn->prepare("SELECT * FROM enquiries WHERE id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getLeadUpdates(mysqli $conn, int $companyId, int $leadId, int $limit = 0): array {
    $sql = "SELECT * FROM enquiry_updates WHERE enquiry_id=? AND company_id=? ORDER BY created_at DESC";
    if ($limit > 0) {
        $sql .= " LIMIT " . $limit;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Only inserts if the enquiry actually belongs to this company —
// silently no-ops otherwise (defense against a spoofed enquiry id).
function addLeadUpdate(mysqli $conn, int $companyId, int $leadId, string $note): bool {
    if ($note === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO enquiry_updates (company_id, enquiry_id, note, created_at) SELECT ?, id, ?, NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('isii', $companyId, $note, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function addLeadFollowup(mysqli $conn, int $companyId, int $leadId, string $note, string $dueDate): bool {
    if ($note === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO enquiry_followups (company_id, enquiry_id, note, due_date, status, created_at) SELECT ?, id, ?, ?, 'Open', NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('issii', $companyId, $note, $dueDate, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function updateLeadStatus(mysqli $conn, int $companyId, int $leadId, string $status): bool {
    $stmt = $conn->prepare("UPDATE enquiries SET status=? WHERE id=? AND company_id=?");
    $stmt->bind_param('sii', $status, $leadId, $companyId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();
    return $updated;
}

function deleteLead(mysqli $conn, int $companyId, int $leadId): bool {
    $stmt = $conn->prepare("DELETE FROM enquiries WHERE id=? AND company_id=?");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    return $deleted;
}

function scheduleSiteVisit(mysqli $conn, int $companyId, int $leadId, string $visitDate, string $visitTime): bool {
    if ($visitDate === '' || $visitTime === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO enquiry_site_visits (company_id, enquiry_id, visit_date, visit_time, created_at) SELECT ?, id, ?, ?, NOW() FROM enquiries WHERE id = ? AND company_id = ?");
    $stmt->bind_param('issii', $companyId, $visitDate, $visitTime, $leadId, $companyId);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function getLatestSiteVisit(mysqli $conn, int $companyId, int $leadId): ?array {
    $stmt = $conn->prepare("SELECT * FROM enquiry_site_visits WHERE enquiry_id=? AND company_id=? ORDER BY visit_date DESC, visit_time DESC LIMIT 1");
    $stmt->bind_param('ii', $leadId, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/* Generates fixed hourly site-visit slots (e.g. 9:00 AM - 10:00 AM) so visits don't overlap. */
function getSiteVisitSlots(string $start = '09:00', string $end = '18:00', int $stepMinutes = 60): array {
    $slots = [];
    $cursor = strtotime($start);
    $endTs = strtotime($end);
    while ($cursor < $endTs) {
        $slotEnd = $cursor + $stepMinutes * 60;
        $slots[] = [
            'start' => date('H:i', $cursor),
            'end' => date('H:i', $slotEnd),
            'label' => date('g:i A', $cursor) . ' - ' . date('g:i A', $slotEnd),
        ];
        $cursor = $slotEnd;
    }
    return $slots;
}

function addDailyNote(mysqli $conn, int $companyId, string $title, string $note): bool {
    if ($title === '' || $note === '') {
        return false;
    }
    $stmt = $conn->prepare("INSERT INTO daily_notes (company_id, title, note, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('iss', $companyId, $title, $note);
    $stmt->execute();
    $stmt->close();
    return true;
}
