<?php
require_once '../config.php';

$conn = getDBConnection();

$stats = [];

// Slot stats
$r = $conn->query("SELECT COUNT(*) as total, SUM(Status='Available') as available, SUM(Status='Occupied') as occupied FROM parking_slots");
$slotStats = $r->fetch_assoc();
$stats['slots'] = $slotStats;

// Active tickets
$r = $conn->query("SELECT COUNT(*) as count FROM tickets WHERE Status='Active'");
$stats['active_tickets'] = $r->fetch_assoc()['count'];

// Today's revenue
$r = $conn->query("SELECT COALESCE(SUM(Amount),0) as revenue FROM payments WHERE DATE(PaymentDate) = CURDATE()");
$stats['today_revenue'] = $r->fetch_assoc()['revenue'];

// Total customers
$r = $conn->query("SELECT COUNT(*) as count FROM customers");
$stats['total_customers'] = $r->fetch_assoc()['count'];

// Total vehicles
$r = $conn->query("SELECT COUNT(*) as count FROM vehicles");
$stats['total_vehicles'] = $r->fetch_assoc()['count'];

// Total revenue all time
$r = $conn->query("SELECT COALESCE(SUM(Amount),0) as revenue FROM payments");
$stats['total_revenue'] = $r->fetch_assoc()['revenue'];

// Recent tickets
$r = $conn->query("
    SELECT t.TicketID, t.EntryTime, t.Status,
           c.Name as CustomerName,
           v.PlateNumber,
           ps.SlotNumber, ps.SlotType
    FROM tickets t
    JOIN customers c ON t.CustomerID = c.CustomerID
    JOIN vehicles v ON t.VehicleID = v.VehicleID
    JOIN parking_slots ps ON t.SlotID = ps.SlotID
    ORDER BY t.CreatedAt DESC LIMIT 5
");
$stats['recent_tickets'] = [];
while ($row = $r->fetch_assoc()) $stats['recent_tickets'][] = $row;

// Slot breakdown
$r = $conn->query("SELECT SlotType, COUNT(*) as total, SUM(Status='Available') as available FROM parking_slots GROUP BY SlotType");
$stats['slot_breakdown'] = [];
while ($row = $r->fetch_assoc()) $stats['slot_breakdown'][] = $row;

$conn->close();
echo json_encode(['success' => true, 'data' => $stats]);
?>