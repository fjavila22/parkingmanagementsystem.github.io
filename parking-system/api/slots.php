<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDBConnection();

function generateSlotID($conn) {
    $result = $conn->query("SELECT SlotID FROM parking_slots ORDER BY CAST(SUBSTRING(SlotID,2) AS UNSIGNED) DESC LIMIT 1");
    if ($result->num_rows === 0) return 'S01';
    $last = $result->fetch_assoc()['SlotID'];
    $num = intval(substr($last, 1)) + 1;
    return 'S' . str_pad($num, 2, '0', STR_PAD_LEFT);
}

function generateSlotNumber($conn, $type) {
    $prefix = $type === 'Premium' ? 'B' : 'A';
    $result = $conn->query("SELECT SlotNumber FROM parking_slots WHERE SlotType = '$type' ORDER BY CAST(SUBSTRING(SlotNumber,2) AS UNSIGNED) DESC LIMIT 1");
    if ($result->num_rows === 0) return $prefix . '1';
    $last = $result->fetch_assoc()['SlotNumber'];
    $num = intval(substr($last, 1)) + 1;
    return $prefix . $num;
}

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;
        $type = $_GET['type'] ?? null;
        $available = $_GET['available'] ?? null;
        
        if ($id) {
            $stmt = $conn->prepare("SELECT * FROM parking_slots WHERE SlotID = ?");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Slot not found']);
            } else {
                echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
            }
        } else {
            $where = [];
            $params = [];
            $types = '';
            if ($type) { $where[] = 'SlotType = ?'; $params[] = $type; $types .= 's'; }
            if ($available === '1') { $where[] = "Status = 'Available'"; }
            
            $sql = "SELECT * FROM parking_slots";
            if (!empty($where)) $sql .= " WHERE " . implode(' AND ', $where);
            $sql .= " ORDER BY SlotType, CAST(SUBSTRING(SlotNumber,2) AS UNSIGNED)";
            
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
        if (!isset($input['SlotType'])) {
            echo json_encode(['success' => false, 'message' => 'SlotType is required']);
            break;
        }
        $id = generateSlotID($conn);
        $slotNum = generateSlotNumber($conn, $input['SlotType']);
        $rate = $input['SlotType'] === 'Premium' ? 50.00 : 30.00;
        $stmt = $conn->prepare("INSERT INTO parking_slots (SlotID, SlotNumber, SlotType, RatePerHour, Status) VALUES (?, ?, ?, ?, 'Available')");
        $stmt->bind_param('sssd', $id, $slotNum, $input['SlotType'], $rate);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Slot added successfully', 'SlotID' => $id, 'SlotNumber' => $slotNum]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add slot: ' . $conn->error]);
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['SlotID'])) {
            echo json_encode(['success' => false, 'message' => 'SlotID is required']);
            break;
        }
        // Only allow status update if manually set
        if (isset($input['Status'])) {
            $stmt = $conn->prepare("UPDATE parking_slots SET Status=? WHERE SlotID=?");
            $stmt->bind_param('ss', $input['Status'], $input['SlotID']);
        } else {
            $stmt = $conn->prepare("UPDATE parking_slots SET SlotType=?, RatePerHour=? WHERE SlotID=?");
            $stmt->bind_param('sds', $input['SlotType'], $input['RatePerHour'], $input['SlotID']);
        }
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Slot updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update slot']);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'SlotID is required']);
            break;
        }
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE SlotID = ? AND Status = 'Active'");
        $check->bind_param('s', $id);
        $check->execute();
        $cnt = $check->get_result()->fetch_assoc()['cnt'];
        if ($cnt > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete an occupied slot']);
            break;
        }
        $stmt = $conn->prepare("DELETE FROM parking_slots WHERE SlotID=?");
        $stmt->bind_param('s', $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Slot deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete slot']);
        }
        break;
}
$conn->close();
?>