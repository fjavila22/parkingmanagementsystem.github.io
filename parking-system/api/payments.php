<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDBConnection();

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;
        $search = $_GET['search'] ?? '';
        
        if ($id) {
            $stmt = $conn->prepare("
                SELECT p.*, t.EntryTime, t.ExitTime,
                       c.Name as CustomerName, c.ContactNumber,
                       v.PlateNumber, v.VehicleType,
                       ps.SlotNumber, ps.SlotType, ps.RatePerHour
                FROM payments p
                JOIN tickets t ON p.TicketID = t.TicketID
                JOIN customers c ON t.CustomerID = c.CustomerID
                JOIN vehicles v ON t.VehicleID = v.VehicleID
                JOIN parking_slots ps ON t.SlotID = ps.SlotID
                WHERE p.PaymentID = ?
            ");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Payment not found']);
            } else {
                echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
            }
        } else {
            $sql = "
                SELECT p.*, t.EntryTime, t.ExitTime,
                       c.Name as CustomerName,
                       v.PlateNumber,
                       ps.SlotNumber, ps.SlotType
                FROM payments p
                JOIN tickets t ON p.TicketID = t.TicketID
                JOIN customers c ON t.CustomerID = c.CustomerID
                JOIN vehicles v ON t.VehicleID = v.VehicleID
                JOIN parking_slots ps ON t.SlotID = ps.SlotID
                WHERE 1=1
            ";
            $params = [];
            $types = '';
            if ($search) {
                $like = "%$search%";
                $sql .= " AND (p.PaymentID LIKE ? OR c.Name LIKE ? OR v.PlateNumber LIKE ? OR p.PaymentMethod LIKE ?)";
                $params = [$like, $like, $like, $like];
                $types = 'ssss';
            }
            $sql .= " ORDER BY p.PaymentDate DESC";
            
            if (!empty($params)) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
            } else {
                $result = $conn->query($sql);
            }
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;
            echo json_encode(['success' => true, 'data' => $data]);
        }
        break;

    case 'DELETE':
        echo json_encode(['success' => false, 'message' => 'Payments cannot be deleted directly. Delete the associated ticket instead.']);
        break;
}
$conn->close();
?>