
<?php

// ==========================================
// DATABASE CONNECTION
// ==========================================

$host = "localhost";
$username = "root";
$password = "";
$database = "online_voting_system";

$conn = new mysqli($host, $username, $password, $database);

// Check Database Connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Set Character Encoding
$conn->set_charset("utf8mb4");


// ==========================================
// TIMEZONE SETTINGS
// ==========================================

// Set PHP timezone to India
date_default_timezone_set('Asia/Kolkata');

// Set MySQL timezone to India
$conn->query("SET time_zone = '+05:30'");


// ==========================================
// AUTOMATIC ELECTION STATUS MANAGEMENT
// ==========================================

// 1. Expired elections become COMPLETED
$conn->query("
    UPDATE elections
    SET status = 'completed'
    WHERE status IN ('upcoming', 'active')
    AND end_date < NOW()
");

// 2. Future elections remain UPCOMING
// Only automatically changes active elections.
// Manually completed elections remain completed.
$conn->query("
    UPDATE elections
    SET status = 'upcoming'
    WHERE status = 'active'
    AND start_date > NOW()
");

// 3. Elections become ACTIVE when their start time arrives
$conn->query("
    UPDATE elections
    SET status = 'active'
    WHERE status = 'upcoming'
    AND start_date <= NOW()
    AND end_date >= NOW()
");


// ==========================================
// END OF DATABASE CONFIGURATION
// ==========================================

?>
