<?php
// profile.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Redirect if user is not logged in
if (!is_logged_in()) {
    $_SESSION['page_message'] = '<div class="error-message">Please log in to view your profile.</div>';
    header('Location: index.php'); // Assuming index.php handles login/registration
    exit();
}

$user_id = get_user_id();
$user_name = get_user_name();
$user_email = ''; // We need to fetch email from DB
$user_role = get_user_role();
$orders = [];
$profile_message = '';

// Fetch user's email from the database
if ($user_id) {
    try {
        $stmt_user = $conn->prepare("SELECT email FROM users WHERE id = ?");
        if ($stmt_user === false) {
            throw new Exception("Failed to prepare user email statement: " . $conn->error);
        }
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        if ($user_data = $result_user->fetch_assoc()) {
            $user_email = $user_data['email'];
        }
        $stmt_user->close();

        // Fetch orders for the logged-in user (reusing the logic from orders.php)
        // SQL query adjusted to match your current 'orders' table schema.
        // It joins with the 'products' table to get product name and image.
        $stmt_orders = $conn->prepare("
            SELECT
                o.id AS order_id,
                o.order_date,
                o.order_status AS status,
                o.total_price AS total_amount,
                o.shippingAddress AS shipping_street_address,
                p.name AS product_name,
                p.image_url
            FROM orders o
            JOIN products p ON o.product_id = p.id
            WHERE o.user_id = ?
            ORDER BY o.order_date DESC, o.id DESC
        ");
        if ($stmt_orders === false) {
            error_log("PROFILE.PHP: Error preparing order statement: " . $conn->error);
            throw new Exception("Failed to prepare order fetch statement: " . $conn->error);
        }
        $stmt_orders->bind_param("i", $user_id);
        $stmt_orders->execute();
        $result_orders = $stmt_orders->get_result();

        $grouped_orders = [];
        while ($row = $result_orders->fetch_assoc()) {
            $order_id = $row['order_id'];

            if (!isset($grouped_orders[$order_id])) {
                $grouped_orders[$order_id] = [
                    'id' => $row['order_id'],
                    'order_date' => $row['order_date'],
                    'status' => $row['status'],
                    'total_amount' => $row['total_amount'],
                    'delivery_fee' => 0.00, // Not in your table, setting default
                    'payment_method' => 'N/A', // Not in your table, setting default
                    'shipping_full_name' => 'Not Provided', // Not in your table, setting default
                    'shipping_street_address' => $row['shipping_street_address'],
                    'shipping_city' => 'Not Provided', // Not in your table, setting default
                    'shipping_province' => 'Not Provided', // Not in your table, setting default
                    'shipping_postal_code' => 'N/A', // Not in your table, setting default
                    'items' => []
                ];
            }
            $grouped_orders[$order_id]['items'][] = [
                'product_name' => $row['product_name'],
                'quantity' => 1, // Assuming quantity 1 as it's not stored
                'price_at_order' => $row['total_amount'], // Assuming total_price is for this item
                'image_url' => $row['image_url']
            ];
        }
        $orders = array_values($grouped_orders);

    } catch (Exception $e) {
        error_log("PROFILE.PHP: Error fetching user data or orders: " . $e->getMessage());
        $profile_message = '<div class="error-message">Error retrieving your profile or orders. Please try again later.</div>';
    }
} else {
    $profile_message = '<div class="error-message">User not identified. Please log in again.</div>';
}

// Get message from session if redirected (e.g., after updating profile)
if (isset($_SESSION['page_message'])) {
    $profile_message = $_SESSION['page_message'];
    unset($_SESSION['page_message']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - C2C Marketplace</title>
    <link rel="stylesheet" href="homepage.css">
    <link rel="stylesheet" href="profile.css"> </head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">C2C marketplace</div>

            <nav>
                <ul class="nav-links">
                    <li><a href="homepage.php">Home</a></li>
                    <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
                        <?php if (function_exists('is_buyer') && is_buyer()): ?>
                            <li><a href="cart.php">My Cart</a></li>
                            <li><a href="orders.php">My Orders</a></li>
                        <?php endif; ?>
                        <?php if (function_exists('is_seller') && is_seller()): ?>
                            <li><a href="dashboard.php" class="sell-button">Sell</a></li>
                        <?php endif; ?>
                        <?php if (function_exists('is_admin') && is_admin()): ?>
                            <li><a href="admin_dashboard.php" class="admin-button">Admin Dashboard</a></li>
                        <?php endif; ?>
                        <!-- <li><a href="#">Messages</a></li> -->
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

    <main class="main-container profile-container">
        <h2 class="profile-title">My Profile</h2>
        <?php if (!empty($profile_message)) echo $profile_message; ?>

        <div class="profile-details">
            <h3>Personal Information</h3>
            <p><strong>Name:</strong> <?= htmlspecialchars($user_name); ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($user_email); ?></p>
            <p><strong>Role:</strong> <?= htmlspecialchars(ucfirst($user_role)); ?></p>
            <button class="edit-profile-btn">Edit Profile (Coming Soon)</button>
        </div>

        <h3 class="orders-section-title">My Orders History</h3>
        <?php if (!empty($orders)): ?>
            <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <h3>Order #<?= htmlspecialchars($order['id']); ?> (<?= date('F j, Y, g:i a', strtotime($order['order_date'])); ?>)</h3>
                    <p>Status: <span class="status <?= strtolower($order['status']); ?>"><?= htmlspecialchars(ucfirst($order['status'])); ?></span></p>
                    <p>Total Amount: R<?= htmlspecialchars(number_format($order['total_amount'], 2)); ?></p>
                    <p>Delivery Fee: R<?= htmlspecialchars(number_format($order['delivery_fee'], 2)); ?></p>
                    <p>Payment Method: <?= htmlspecialchars($order['payment_method']); ?></p>

                    <h4>Shipping Address:</h4>
                    <p><?= htmlspecialchars($order['shipping_full_name']); ?></p>
                    <p><?= htmlspecialchars($order['shipping_street_address']); ?></p>
                    <p><?= htmlspecialchars($order['shipping_city']); ?>, <?= htmlspecialchars($order['shipping_province']); ?></p>
                    <p><?= htmlspecialchars($order['shipping_postal_code']); ?></p>

                    <h4>Items:</h4>
                    <ul class="order-items-list">
                        <?php foreach ($order['items'] as $item): ?>
                            <li>
                                <?php
                                if (!empty($item['image_url']) && file_exists($item['image_url'])):
                                ?>
                                    <img src="<?= htmlspecialchars($item['image_url']); ?>" alt="<?= htmlspecialchars($item['product_name']); ?>" onerror="this.onerror=null;this.src='placeholder-image.jpg';">
                                <?php else: ?>
                                    <img src="placeholder-image.jpg" alt="No image available">
                                <?php endif; ?>
                                <div class="item-details">
                                    <span class="item-name"><?= htmlspecialchars($item['product_name']); ?></span>
                                    <p class="item-price">Qty: <?= htmlspecialchars($item['quantity']); ?> x R<?= htmlspecialchars(number_format($item['price_at_order'], 2)); ?> = R<?= htmlspecialchars(number_format($item['quantity'] * $item['price_at_order'], 2)); ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
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