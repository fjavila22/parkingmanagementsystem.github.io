<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$conn = getDBConnection();

function generateCustomerID($conn) {
    $result = $conn->query("SELECT CustomerID FROM customers ORDER BY CustomerID DESC LIMIT 1");
    if ($result->num_rows === 0) return 'C01';
    $last = $result->fetch_assoc()['CustomerID'];
    $num = intval(substr($last, 1)) + 1;
    return 'C' . str_pad($num, 2, '0', STR_PAD_LEFT);
}

switch ($method) {
    case 'GET':
        $id = $_GET['id'] ?? null;
        if ($id) {
            $stmt = $conn->prepare("SELECT c.*, COUNT(v.VehicleID) as vehicle_count FROM customers c LEFT JOIN vehicles v ON c.CustomerID = v.CustomerID WHERE c.CustomerID = ? GROUP BY c.CustomerID");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Customer not found']);
            } else {
                echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
            }
        } else {
            $search = $_GET['search'] ?? '';
            if ($search) {
                $like = "%$search%";
                $stmt = $conn->prepare("SELECT c.*, COUNT(v.VehicleID) as vehicle_count FROM customers c LEFT JOIN vehicles v ON c.CustomerID = v.CustomerID WHERE c.Name LIKE ? OR c.ContactNumber LIKE ? OR c.CustomerID LIKE ? GROUP BY c.CustomerID ORDER BY c.CreatedAt DESC");
                $stmt->bind_param('sss', $like, $like, $like);
            } else {
                $stmt = $conn->prepare("SELECT c.*, COUNT(v.VehicleID) as vehicle_count FROM customers c LEFT JOIN vehicles v ON c.CustomerID = v.CustomerID GROUP BY c.CustomerID ORDER BY c.CreatedAt DESC");
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
        if (!isset($input['Name']) || !isset($input['ContactNumber'])) {
            echo json_encode(['success' => false, 'message' => 'Name and ContactNumber are required']);
            break;
        }
        $id = generateCustomerID($conn);
        $stmt = $conn->prepare("INSERT INTO customers (CustomerID, Name, ContactNumber) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $id, $input['Name'], $input['ContactNumber']);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Customer added successfully', 'CustomerID' => $id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add customer: ' . $conn->error]);
        }
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['CustomerID'])) {
            echo json_encode(['success' => false, 'message' => 'CustomerID is required']);
            break;
        }
        $stmt = $conn->prepare("UPDATE customers SET Name=?, ContactNumber=? WHERE CustomerID=?");
        $stmt->bind_param('sss', $input['Name'], $input['ContactNumber'], $input['CustomerID']);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Customer updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update customer']);
        }
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'CustomerID is required']);
            break;
        }
        // Check if customer has active tickets
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM tickets WHERE CustomerID = ? AND Status = 'Active'");
        $check->bind_param('s', $id);
        $check->execute();
        $cnt = $check->get_result()->fetch_assoc()['cnt'];
        if ($cnt > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete customer with active parking tickets']);
            break;
        }
        $stmt = $conn->prepare("DELETE FROM customers WHERE CustomerID=?");
        $stmt->bind_param('s', $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete customer']);
        }
        break;
}
$conn->close();
?>