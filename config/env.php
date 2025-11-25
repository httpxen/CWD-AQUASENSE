<?php
// config/env.php
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

// LOAD .env from root
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Force to putenv for compatibility
foreach ($_ENV as $key => $value) {
    putenv("$key=$value");
}