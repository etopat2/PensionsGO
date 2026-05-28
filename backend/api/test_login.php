<?php
// test_login.php - Simple test to verify login works
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simple direct database test
$conn = new mysqli('localhost', 'root', '', 'pension_db');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Test if user exists
$sql = "SELECT userId, userName, userPassword FROM tb_users WHERE userEmail = 'etomet2patrick@gmail.com' LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "User found: " . $user['userName'] . "<br>";
    echo "User ID: " . $user['userId'] . "<br>";
    echo "Password hash: " . substr($user['userPassword'], 0, 20) . "...<br>";
} else {
    echo "User not found<br>";
}

// Test password
$testPassword = 'your_password_here'; // Replace with actual password
if (password_verify($testPassword, $user['userPassword'])) {
    echo "✓ Password verified successfully!<br>";
} else {
    echo "✗ Password verification failed<br>";
}

$conn->close();
?>