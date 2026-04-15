<?php
$conn = new mysqli('localhost', 'root', '', '');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$sql = "
CREATE DATABASE IF NOT EXISTS parking_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE parking_management;

CREATE TABLE IF NOT EXISTS customers (
    CustomerID VARCHAR(10) PRIMARY KEY,
    Name VARCHAR(100) NOT NULL,
    ContactNumber VARCHAR(20) NOT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicles (
    VehicleID VARCHAR(10) PRIMARY KEY,
    PlateNumber VARCHAR(20) NOT NULL UNIQUE,
    VehicleType VARCHAR(50) NOT NULL,
    CustomerID VARCHAR(10) NOT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (CustomerID) REFERENCES customers(CustomerID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS parking_slots (
    SlotID VARCHAR(10) PRIMARY KEY,
    SlotNumber VARCHAR(10) NOT NULL UNIQUE,
    SlotType ENUM('Standard','Premium') NOT NULL,
    RatePerHour DECIMAL(10,2) NOT NULL,
    Status ENUM('Available','Occupied') DEFAULT 'Available',
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tickets (
    TicketID VARCHAR(10) PRIMARY KEY,
    EntryTime DATETIME NOT NULL,
    ExitTime DATETIME NULL,
    CustomerID VARCHAR(10) NOT NULL,
    VehicleID VARCHAR(10) NOT NULL,
    SlotID VARCHAR(10) NOT NULL,
    PaymentID VARCHAR(10) NULL,
    Status ENUM('Active','Completed') DEFAULT 'Active',
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (CustomerID) REFERENCES customers(CustomerID),
    FOREIGN KEY (VehicleID) REFERENCES vehicles(VehicleID),
    FOREIGN KEY (SlotID) REFERENCES parking_slots(SlotID)
);

CREATE TABLE IF NOT EXISTS payments (
    PaymentID VARCHAR(10) PRIMARY KEY,
    TicketID VARCHAR(10) NOT NULL,
    Amount DECIMAL(10,2) NOT NULL,
    PaymentMethod ENUM('Cash','Credit Card','Debit Card','GCash','PayMaya') NOT NULL,
    PaymentDate DATETIME NOT NULL,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (TicketID) REFERENCES tickets(TicketID)
);

INSERT IGNORE INTO parking_slots (SlotID, SlotNumber, SlotType, RatePerHour, Status) VALUES
('S01','A1','Standard',30.00,'Available'),
('S02','A2','Standard',30.00,'Available'),
('S03','A3','Standard',30.00,'Available'),
('S04','A4','Standard',30.00,'Available'),
('S05','A5','Standard',30.00,'Available'),
('S06','A6','Standard',30.00,'Available'),
('S07','A7','Standard',30.00,'Available'),
('S08','A8','Standard',30.00,'Available'),
('S09','A9','Standard',30.00,'Available'),
('S10','A10','Standard',30.00,'Available'),
('S11','B1','Premium',50.00,'Available'),
('S12','B2','Premium',50.00,'Available'),
('S13','B3','Premium',50.00,'Available'),
('S14','B4','Premium',50.00,'Available'),
('S15','B5','Premium',50.00,'Available');
";

$statements = array_filter(array_map('trim', explode(';', $sql)));
$errors = [];
$success = 0;

foreach ($statements as $statement) {
    if (!empty($statement)) {
        if ($conn->query($statement) === TRUE) {
            $success++;
        } else {
            $errors[] = $conn->error . ' | SQL: ' . substr($statement, 0, 100);
        }
    }
}

$conn->close();

if (empty($errors)) {
    echo json_encode(['success' => true, 'message' => 'Database setup complete! ' . $success . ' statements executed successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Setup completed with some errors.', 'errors' => $errors, 'success_count' => $success]);
}
?>