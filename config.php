<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Railway Database configuration
$host = $_ENV['MYSQLHOST'] ?? 'localhost';
$username = $_ENV['MYSQLUSER'] ?? 'root'; // Fixed variable name from $users to $username
$password = $_ENV['MYSQLPASSWORD'] ?? '';
$database = $_ENV['MYSQLDATABASE'] ?? 'c2c_marketplace'; // Keep your original database name as fallback

// Get port separately for Railway
$port = $_ENV['MYSQLPORT'] ?? '3306';

// MySQLi Connection with port specification for Railway
$conn = new mysqli($host, $username, $password, $database, $port);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set character set for the connection
$conn->set_charset("utf8mb4"); // Using utf8mb4 for broader character support

/**
 * Checks if a user is currently logged in.
 * @return bool True if a user session exists, false otherwise.
 */
function is_logged_in() {
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']);
}

/**
 * Checks if the logged-in user has the 'seller' role.
 * Requires the user to be logged in.
 * @return bool True if the user is a seller, false otherwise.
 */
function is_seller() {
    return is_logged_in() && $_SESSION['user']['role'] === 'seller';
}

/**
 * Checks if the logged-in user has the 'buyer' role.
 * Requires the user to be logged in.
 * @return bool True if the user is a buyer, false otherwise.
 */
function is_buyer() {
    return is_logged_in() && $_SESSION['user']['role'] === 'buyer';
}

/**
 * Checks if the logged-in user has the 'admin' role.
 * Requires the user to be logged in.
 * @return bool True if the user is an admin, false otherwise.
 */
function is_admin() {
    return is_logged_in() && $_SESSION['user']['role'] === 'admin';
}

/**
 * Gets the ID of the currently logged-in user.
 * @return int|null The user ID if logged in, null otherwise.
 */
function get_user_id() {
    return is_logged_in() ? $_SESSION['user']['id'] : null;
}

/**
 * Gets the name of the currently logged-in user.
 * @return string|null The user name if logged in, null otherwise.
 */
function get_user_name() {
    return is_logged_in() ? $_SESSION['user']['name'] : null;
}

/**
 * Gets the role of the currently logged-in user.
 * @return string|null The user role (e.g., 'buyer', 'seller', 'admin') if logged in, null otherwise.
 */
function get_user_role() {
    return is_logged_in() ? $_SESSION['user']['role'] : null;
}

/**
 * Redirects to the login page if the user is not logged in.
 */
function require_login() {
    if (!is_logged_in()) {
        header("Location: index.php"); // Redirect to index.php which handles login
        exit();
    }
}

/**
 * Redirects to the login page if the user is not logged in or not a seller.
 */
function require_seller() {
    if (!is_logged_in() || !is_seller()) {
        header("Location: index.php");
        exit();
    }
}

/**
 * Redirects to the login page if the user is not logged in or not a buyer.
 */
function require_buyer() {
    if (!is_logged_in() || !is_buyer()) {
        header("Location: index.php");
        exit();
    }
}

/**
 * Redirects to the login page if the user is not logged in or not an admin.
 */
function require_admin() {
    if (!is_logged_in() || !is_admin()) {
        header("Location: index.php");
        exit();
    }
}

?>
