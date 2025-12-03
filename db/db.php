<?php
$host = "localhost";
$user = "root";  // Updated to match hPanel
$pass = "";  // Keep this; reset if needed
$dbname = "cwd_aquasense";

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>