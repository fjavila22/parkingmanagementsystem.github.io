<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDBConnection();

function generateVehicleID($conn) {
    $result = $conn->query("SELECT VehicleID FROM vehicles ORDER BY VehicleID DESC LIMIT 1");
    if ($result->num_rows === 0) return 'V001';
    $last = $result->fetch_assoc()['VehicleID'];
    $num = intval(substr($last, 1)) + 1;
    return 'V' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;
        $customer_id = $_GET['customer_id'] ?? null;
        if ($id) {
            $stmt = $conn->prepare("SELECT v.*, c.Name as CustomerName FROM vehicles v JOIN customers c ON v.CustomerID = c.CustomerID WHERE v.VehicleID = ?");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Vehicle not found']);
            } else {
                echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
            }
        } elseif ($customer_id) {
            $stmt = $conn->prepare("SELECT v.*, c.Name as CustomerName FROM vehicles v JOIN customers c ON v.CustomerID = c.CustomerID WHERE v.CustomerID = ? ORDER BY v.CreatedAt DESC");
            $stmt->bind_param('s', $customer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            $search = $_GET['search'] ?? '';
            if ($search) {
                $like = "%$search%";
                $stmt = $conn->prepare("SELECT v.*, c.Name as CustomerName FROM vehicles v JOIN customers c ON v.CustomerID = c.CustomerID WHERE v.PlateNumber LIKE ? OR v.VehicleType LIKE ? OR c.Name LIKE ? OR v.VehicleID LIKE ? ORDER BY v.CreatedAt DESC");
                $stmt->bind_param('ssss', $like, $like, $like, $like);
            } else {
                $stmt = $conn->prepare("SELECT v.*, c.Name as CustomerName FROM vehicles v JOIN customers c ON v.CustomerID = c.CustomerID ORDER BY v.CreatedAt DESC");
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $data = [];
            while ($row = $result->fetch_assoc()) $data[] = $row;
            echo json_encode(['success' => true, 'data' => $data]);
        }
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['PlateNumber']) || !isset($input['VehicleType']) || !isset($input['CustomerID'])) {
            echo json_encode(['success' => false, 'message' => 'PlateNumber, VehicleType, and CustomerID are required']);
            break;
        }
        // Check plate number uniqueness
        $check = $conn->prepare("SELECT VehicleID FROM vehicles WHERE PlateNumber = ?");
        $check->bind_param('s', $input['PlateNumber']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Plate number already exists']);
            break;
        }
        $id = generateVehicleID($conn);
        $stmt = $conn->prepare("INSERT INTO vehicles (VehicleID, PlateNumber, VehicleType, CustomerID) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $id, $input['PlateNumber'], $input['VehicleType'], $input['CustomerID']);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Vehicle added successfully', 'VehicleID' => $id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add vehicle: ' . $conn->error]);
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['VehicleID'])) {
            echo json_encode(['success' => false, 'message' => 'VehicleID is required']);
            break;
        }
        // Check plate uniqueness excluding current vehicle
        $check = $conn->prepare("SELECT VehicleID FROM vehicles WHERE PlateNumber = ? AND VehicleID != ?");
        $check->bind_param('ss', $input['PlateNumber'], $input['VehicleID']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Plate number already exists']);
            break;
        }
        $stmt = $conn->prepare("UPDATE vehicles SET PlateNumber=?, VehicleType=?, CustomerID=? WHERE VehicleID=?");
        $stmt->bind_param('ssss', $input['PlateNumber'], $input['VehicleType'], $input['CustomerID'], $input['VehicleID']);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Vehicle updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update vehicle']);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'VehicleID is required']);
            break;
        }
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE VehicleID = ? AND Status = 'Active'");
        $check->bind_param('s', $id);
        $check->execute();
        $cnt = $check->get_result()->fetch_assoc()['cnt'];
        if ($cnt > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete vehicle with active parking tickets']);
            break;
        }
        $stmt = $conn->prepare("DELETE FROM vehicles WHERE VehicleID=?");
        $stmt->bind_param('s', $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Vehicle deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete vehicle']);
        }
        break;
}
$conn->close();
?>