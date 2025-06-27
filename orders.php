<?php
// orders.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php'; // Includes database connection ($conn) and user functions

// Ensure user is logged in and is a buyer to view orders
if (!is_logged_in()) {
    $_SESSION['page_message'] = '<div class="error-message">Please log in to view your orders.</div>';
    header('Location: index.php'); // Redirect to login page
    exit();
}

if (!is_buyer() && !is_admin()) {
    $_SESSION['page_message'] = '<div class="error-message">You do not have permission to view this page.</div>';
    header('Location: homepage.php'); // Redirect to homepage
    exit();
}

$user_id = get_user_id();
$orders = [];
$message = '';

if ($user_id) {
    try {
        // Query to fetch main order details for each distinct order ID.
        // It's crucial that your `orders` table has `delivery_fee`
        // and `shipping_street_address` (if you are using that in inserts).
        // Based on previous DESCRIBE, you have `shippingAddress` and `shipping_street_address`.
        // The query uses `shippingAddress` and aliases it.
        // Also, it now explicitly selects `delivery_fee`.
        $stmt = $conn->prepare("
            SELECT
                id AS order_id,
                order_date,
                status AS status, -- Assuming 'status' is the actual column name in DB
                total_amount,
                delivery_fee,              
                payment_method,
                shipping_full_name,
                shipping_city,
                shipping_province,
                shipping_postal_code,
                shipping_phone
            FROM orders
            WHERE user_id = ?
            ORDER BY order_date DESC, id DESC
        ");

        if ($stmt === false) {
            error_log("Order.php: Error preparing order list statement: " . $conn->error);
            throw new Exception("Failed to prepare order list fetch statement: " . $conn->error);
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($orders)) {
            $message = '<div class="info-message">You have not placed any orders yet.</div>';
        }

    } catch (Exception $e) {
        error_log("ORDERS.PHP: General error fetching user orders: " . $e->getMessage());
        $message = '<div class="error-message">Error retrieving your orders. Please try again later.'.htmlspecialchars($e->getMessage()).'</div>';
    }
} else {
    $message = '<div class="error-message">Could not identify user. Please log in again.</div>';
}

// Get message from session if redirected (e.g., after checkout)
if (isset($_SESSION['page_message'])) {
    $message = $_SESSION['page_message'];
    unset($_SESSION['page_message']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - C2C Marketplace</title>
    <link rel="stylesheet" href="homepage.css">
    <style>
        /* Basic styling for orders page - consider moving to orders.css or extending homepage.css */
        .order-card {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 25px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .order-card h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .order-card p {
            margin-bottom: 5px;
            color: #555;
        }
        .order-card .status {
            font-weight: bold;
            color: #007bff; /* Example: blue for pending */
        }
        .order-card .status.completed { color: green; }
        .order-card .status.cancelled { color: red; }

        .order-items-list {
            list-style: none;
            padding: 0;
            margin-top: 15px;
            border-top: 1px dashed #eee;
            padding-top: 15px;
        }
        .order-items-list li {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dotted #f0f0f0;
        }
        .order-items-list li:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }
        .order-items-list img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            margin-right: 15px;
            border-radius: 4px;
        }
        .order-items-list .item-details {
            flex-grow: 1;
        }
        .order-items-list .item-name {
            font-weight: bold;
            color: #444;
        }
        .order-items-list .item-price {
            font-size: 0.9em;
            color: #777;
        }
        .total-summary {
            text-align: right;
            margin-top: 20px;
            font-size: 1.1em;
            font-weight: bold;
            color: #333;
        }
        .error-message, .info-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .info-message {
            background-color: #e2e3e5;
            color: #383d41;
            border-color: #d6d8db;
        }

        /* Re-using header/footer styles from homepage.css */
        .main-container {
            padding: 40px 20px;
            max-width: 1000px; /* Adjust as needed */
            margin: 20px auto;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .cart-title {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            font-size: 2.2em;
        }
        /* New style for the "View Details" button */
        .view-details-btn {
            display: inline-block;
            padding: 10px 15px;
            background-color: #007bff;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            margin-top: 15px;
            transition: background-color 0.3s ease;
        }
        .view-details-btn:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">C2C marketplace</div>

            <nav>
                <ul class="nav-links">
                    <li><a href="homepage.php">Home</a></li>
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <li><a href="cart.php">My Cart</a></li>
                        <li><a href="orders.php">My Orders</a></li>

                        <?php if (function_exists('is_seller') && is_seller()): ?>
                            <li><a href="dashboard.php" class="sell-button">Sell</a></li>
                        <?php endif; ?>
                        <?php if (function_exists('is_admin') && is_admin()): ?>
                            <li><a href="admin_dashboard.php" class="admin-button">Admin Dashboard</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </nav>

            <div class="user-actions">
                <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                    <span class="user-greeting">Hello, <?= htmlspecialchars(get_user_name()); ?>!</span>
                    <div class="dropdown-content">
                        <a href="profile.php">Profile</a>
                        <a href="logout.php" class="logout-btn">Logout</a>
                    </div>
                <?php else: ?>
                    <a href="index.php?form=login" class="login-button">Login</a>
                    <a href="index.php?form=register" class="register-button">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="main-container">
        <h2 class="cart-title">My Orders</h2>
        <?php if (!empty($message)) echo $message; ?>

        <?php if (!empty($orders)): ?>
            <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <h3>Order #<?= htmlspecialchars($order['order_id']); ?> (<?= date('F j, Y, g:i a', strtotime($order['order_date'])); ?>)</h3>
                    <p>Status: <span class="status <?= strtolower($order['status']); ?>"><?= htmlspecialchars(ucfirst($order['status'])); ?></span></p>
                    <p>Total Amount: R<?= htmlspecialchars(number_format($order['total_amount'], 2)); ?></p>
                    <p>Delivery Fee: R<?= htmlspecialchars(number_format($order['delivery_fee'] ?? 0, 2)); ?></p>
                    <p>Payment Method: <?= htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></p>

                    <h4>Shipping Address:</h4>
                    <p><?= htmlspecialchars($order['shipping_full_name'] ?? 'Not Provided'); ?></p>
                    <p><?= htmlspecialchars($order['shipping_street_address'] ?? 'Not Provided'); ?></p>
                    <p><?= htmlspecialchars($order['shipping_city'] ?? 'Not Provided'); ?>, <?= htmlspecialchars($order['shipping_province'] ?? 'Not Provided'); ?></p>
                    <p><?= htmlspecialchars($order['shipping_postal_code'] ?? 'N/A'); ?></p>
                    <p>Phone: <?= htmlspecialchars($order['shipping_phone'] ?? 'N/A'); ?></p>

                    <h4>Items:</h4>
                    <ul class="order-items-list">
                        <?php
                       
                        ?>
                        <li><div class="item-details"><span class="item-name">Click "View Full Order Details" for item breakdown.</span></div></li>
                    </ul>
                    <a href="order_confirmation.php?order_id=<?= htmlspecialchars($order['order_id']); ?>" class="view-details-btn">View Full Order Details</a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="no-orders-yet">No orders found.</p>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y'); ?> C2C Marketplace. All rights reserved.</p>
            <nav>
                <ul>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Contact Us - muhammedbahadur07@gmail.com</a></li>
                </ul>
            </nav>
        </div>
    </footer>
</body>
</html>