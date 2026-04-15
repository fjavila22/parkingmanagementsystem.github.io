<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDBConnection();

function generateTicketID($conn) {
    $result = $conn->query("SELECT TicketID FROM tickets ORDER BY CAST(SUBSTRING(TicketID,2) AS UNSIGNED) DESC LIMIT 1");
    if ($result->num_rows === 0) return 'T01';
    $last = $result->fetch_assoc()['TicketID'];
    $num = intval(substr($last, 1)) + 1;
    return 'T' . str_pad($num, 2, '0', STR_PAD_LEFT);
}

function generatePaymentID($conn) {
    $result = $conn->query("SELECT PaymentID FROM payments ORDER BY CAST(SUBSTRING(PaymentID,2) AS UNSIGNED) DESC LIMIT 1");
    if ($result->num_rows === 0) return 'P01';
    $last = $result->fetch_assoc()['PaymentID'];
    $num = intval(substr($last, 1)) + 1;
    return 'P' . str_pad($num, 2, '0', STR_PAD_LEFT);
}

$action = $_GET['action'] ?? null;

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;
        if ($id) {
            $stmt = $conn->prepare("
                SELECT t.*, 
                       c.Name as CustomerName, c.ContactNumber,
                       v.PlateNumber, v.VehicleType,
                       ps.SlotNumber, ps.SlotType, ps.RatePerHour,
                       p.Amount, p.PaymentMethod, p.PaymentDate
                FROM tickets t
                JOIN customers c ON t.CustomerID = c.CustomerID
                JOIN vehicles v ON t.VehicleID = v.VehicleID
                JOIN parking_slots ps ON t.SlotID = ps.SlotID
                LEFT JOIN payments p ON t.PaymentID = p.PaymentID
                WHERE t.TicketID = ?
            ");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Ticket not found']);
            } else {
                echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
            }
        } else {
            $status = $_GET['status'] ?? null;
            $search = $_GET['search'] ?? '';
            
            $sql = "
                SELECT t.*, 
                       c.Name as CustomerName,
                       v.PlateNumber, v.VehicleType,
                       ps.SlotNumber, ps.SlotType, ps.RatePerHour,
                       p.Amount, p.PaymentMethod
                FROM tickets t
                JOIN customers c ON t.CustomerID = c.CustomerID
                JOIN vehicles v ON t.VehicleID = v.VehicleID
                JOIN parking_slots ps ON t.SlotID = ps.SlotID
                LEFT JOIN payments p ON t.PaymentID = p.PaymentID
                WHERE 1=1
            ";
            $params = [];
            $types = '';
            
            if ($status) {
                $sql .= " AND t.Status = ?";
                $params[] = $status;
                $types .= 's';
            }
            if ($search) {
                $like = "%$search%";
                $sql .= " AND (t.TicketID LIKE ? OR c.Name LIKE ? OR v.PlateNumber LIKE ? OR ps.SlotNumber LIKE ?)";
                $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
                $types .= 'ssss';
            }
            $sql .= " ORDER BY t.CreatedAt DESC";
            
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

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'checkout') {
            // Process checkout and payment
            $ticketID = $input['TicketID'] ?? null;
            $paymentMethod = $input['PaymentMethod'] ?? null;
            
            if (!$ticketID || !$paymentMethod) {
                echo json_encode(['success' => false, 'message' => 'TicketID and PaymentMethod are required']);
                break;
            }
            
            // Get ticket with slot info
            $stmt = $conn->prepare("
                SELECT t.*, ps.RatePerHour, ps.SlotType, ps.SlotID
                FROM tickets t 
                JOIN parking_slots ps ON t.SlotID = ps.SlotID 
                WHERE t.TicketID = ? AND t.Status = 'Active'
            ");
            $stmt->bind_param('s', $ticketID);
            $stmt->execute();
            $ticket = $stmt->get_result()->fetch_assoc();
            
            if (!$ticket) {
                echo json_encode(['success' => false, 'message' => 'Active ticket not found']);
                break;
            }
            
            $exitTime = date('Y-m-d H:i:s');
            $entryTime = $ticket['EntryTime'];
            
            // Calculate duration in hours (minimum 1 hour)
            $entry = new DateTime($entryTime);
            $exit = new DateTime($exitTime);
            $diffSeconds = $exit->getTimestamp() - $entry->getTimestamp();
            $diffHours = $diffSeconds / 3600;
            $billedHours = max(1, ceil($diffHours * 4) / 4); // Round up to nearest 15 minutes
            $amount = round($billedHours * $ticket['RatePerHour'], 2);
            
            // Use override amount if provided (for manual adjustment)
            if (isset($input['Amount']) && $input['Amount'] > 0) {
                $amount = floatval($input['Amount']);
            }
            
            $conn->begin_transaction();
            try {
                // Create payment
                $paymentID = generatePaymentID($conn);
                $stmt = $conn->prepare("INSERT INTO payments (PaymentID, TicketID, Amount, PaymentMethod, PaymentDate) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('ssdss', $paymentID, $ticketID, $amount, $paymentMethod, $exitTime);
                $stmt->execute();
                
                // Update ticket
                $stmt = $conn->prepare("UPDATE tickets SET ExitTime=?, PaymentID=?, Status='Completed' WHERE TicketID=?");
                $stmt->bind_param('sss', $exitTime, $paymentID, $ticketID);
                $stmt->execute();
                
                // Free the parking slot
                $stmt = $conn->prepare("UPDATE parking_slots SET Status='Available' WHERE SlotID=?");
                $stmt->bind_param('s', $ticket['SlotID']);
                $stmt->execute();
                
                $conn->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => 'Checkout successful',
                    'PaymentID' => $paymentID,
                    'Amount' => $amount,
                    'Duration' => round($diffHours, 2),
                    'BilledHours' => $billedHours,
                    'ExitTime' => $exitTime
                ]);
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Checkout failed: ' . $e->getMessage()]);
            }
            break;
        }
        
        // Create new ticket (entry)
        if (!isset($input['CustomerID']) || !isset($input['VehicleID']) || !isset($input['SlotID'])) {
            echo json_encode(['success' => false, 'message' => 'CustomerID, VehicleID, and SlotID are required']);
            break;
        }
        
        // Validate slot availability
        $slotCheck = $conn->prepare("SELECT * FROM parking_slots WHERE SlotID = ?");
        $slotCheck->bind_param('s', $input['SlotID']);
        $slotCheck->execute();
        $slot = $slotCheck->get_result()->fetch_assoc();
        
        if (!$slot) {
            echo json_encode(['success' => false, 'message' => 'Slot not found']);
            break;
        }
        
        // For premium slots, check vehicle count (max 2)
        if ($slot['SlotType'] === 'Premium') {
            $countCheck = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE SlotID = ? AND Status = 'Active'");
            $countCheck->bind_param('s', $input['SlotID']);
            $countCheck->execute();
            $cnt = $countCheck->get_result()->fetch_assoc()['cnt'];
            if ($cnt >= 2) {
                echo json_encode(['success' => false, 'message' => 'Premium slot is full (maximum 2 vehicles)']);
                break;
            }
        } elseif ($slot['Status'] === 'Occupied') {
            echo json_encode(['success' => false, 'message' => 'Slot is already occupied']);
            break;
        }
        
        // Check vehicle doesn't already have active ticket
        $vCheck = $conn->prepare("SELECT TicketID FROM tickets WHERE VehicleID = ? AND Status = 'Active'");
        $vCheck->bind_param('s', $input['VehicleID']);
        $vCheck->execute();
        if ($vCheck->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'This vehicle already has an active parking ticket']);
            break;
        }
        
        // Verify vehicle belongs to customer
        $vOwner = $conn->prepare("SELECT CustomerID FROM vehicles WHERE VehicleID = ?");
        $vOwner->bind_param('s', $input['VehicleID']);
        $vOwner->execute();
        $owner = $vOwner->get_result()->fetch_assoc();
        if (!$owner || $owner['CustomerID'] !== $input['CustomerID']) {
            echo json_encode(['success' => false, 'message' => 'Vehicle does not belong to this customer']);
            break;
        }
        
        $conn->begin_transaction();
        try {
            $ticketID = generateTicketID($conn);
            $entryTime = date('Y-m-d H:i:s');
            
            $stmt = $conn->prepare("INSERT INTO tickets (TicketID, EntryTime, CustomerID, VehicleID, SlotID) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssss', $ticketID, $entryTime, $input['CustomerID'], $input['VehicleID'], $input['SlotID']);
            $stmt->execute();
            
            // Mark slot as occupied
            $stmt = $conn->prepare("UPDATE parking_slots SET Status='Occupied' WHERE SlotID=?");
            $stmt->bind_param('s', $input['SlotID']);
            $stmt->execute();
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Ticket created successfully', 'TicketID' => $ticketID, 'EntryTime' => $entryTime]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create ticket: ' . $e->getMessage()]);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'TicketID is required']);
            break;
        }
        // Only allow deleting completed tickets
        $check = $conn->prepare("SELECT Status, SlotID FROM tickets WHERE TicketID = ?");
        $check->bind_param('s', $id);
        $check->execute();
        $ticket = $check->get_result()->fetch_assoc();
        if (!$ticket) {
            echo json_encode(['success' => false, 'message' => 'Ticket not found']);
            break;
        }
        if ($ticket['Status'] === 'Active') {
            echo json_encode(['success' => false, 'message' => 'Cannot delete an active ticket. Please checkout first.']);
            break;
        }
        // Delete associated payment first
        $conn->prepare("DELETE FROM payments WHERE TicketID = ?")->execute() ?? null;
        $delPay = $conn->prepare("DELETE FROM payments WHERE TicketID = ?");
        $delPay->bind_param('s', $id);
        $delPay->execute();
        
        $stmt = $conn->prepare("DELETE FROM tickets WHERE TicketID=?");
        $stmt->bind_param('s', $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Ticket deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete ticket']);
        }
        break;
}
$conn->close();
?>