<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
$host = 'localhost';
$dbname = 'c2c_marketplace'; 
$username = 'root'; 
$password = ''; 
// PDO Connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// MySQLi Connection (for backward compatibility)
try {
    $conn = new mysqli($host, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $conn->set_charset("utf8");
} catch (Exception $e) {
    die("Connection failed: " . $e->getMessage());
}

// Function to check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

// Function to check if user is a seller
function is_seller() {
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'seller';
}

// Function to check if user is a buyer
function is_buyer() {
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'buyer';
}

// Function to check if user is an admin
function is_admin() {
    return isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
}

// Function to get current user ID
function get_user_id() {
    return is_logged_in() ? $_SESSION['user']['id'] : null;
}

// Function to get current user name
function get_user_name() {
    return is_logged_in() ? $_SESSION['user']['name'] : null;
}

// Function to get current user role
function get_user_role() {
    return is_logged_in() ? $_SESSION['user']['role'] : null;
}

// Function to redirect if not logged in
function require_login() {
    if (!is_logged_in()) {
        header("Location: index.php");
        exit();
    }
}

// Function to redirect if not seller
function require_seller() {
    if (!is_logged_in() || !is_seller()) {
        header("Location: index.php");
        exit();
    }
}

// Function to redirect if not buyer
function require_buyer() {
    if (!is_logged_in() || !is_buyer()) {
        header("Location: index.php");
        exit();
    }
}

// Function to redirect if not admin
function require_admin() {
    if (!is_logged_in() || !is_admin()) {
        header("Location: index.php");
        exit();
    }
}
?>