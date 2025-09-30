<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "cwd_aquasense";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}