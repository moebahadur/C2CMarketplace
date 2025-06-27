<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php'; // Include your database connection and helper functions (is_logged_in, is_buyer, get_user_name)

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a buyer. Redirect if not.
// Ensure is_logged_in() and is_buyer() functions are properly defined in config.php
if (!is_logged_in() || !is_buyer()) {
    header('Location: index.php');
    exit();
}

// Get order ID from URL parameter and sanitize it
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

// If order ID is invalid (not a positive integer), set error message and redirect
if ($order_id <= 0) {
    $_SESSION['page_message'] = '<div class="error-message">Invalid order ID provided. Please select an order from your <a href="orders.php">order history</a>.</div>';
    header('Location: orders.php'); // Redirect to the orders list page
    exit();
}

$user_id = $_SESSION['user']['id']; // Get the logged-in user's ID
$order = null; // This will hold the main order details fetched from the 'orders' table
$order_items = []; // This will hold the individual items for the order
$message = ''; // For displaying success/error messages from session
$delivery_fee = 100; // Define your fixed delivery fee here
$subtotal = 0; // Initialize subtotal before calculation

// Retrieve message from session (e.g., success message from checkout)
if (isset($_SESSION['page_message'])) {
    $message = $_SESSION['page_message'];
    unset($_SESSION['page_message']); // Clear the message after displaying
}

try {
    // --- Step 1: Fetch the main order details ---
    // Prepare statement to select all columns from the 'orders' table
    // Ensure the order belongs to the current logged-in user for security
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc(); // Fetch the single row into the $order array
    $stmt->close();

    // If no order is found with the given ID for the current user, redirect
    if (!$order) {
        $_SESSION['page_message'] = '<div class="error-message">Order not found or you don\'t have permission to view it.</div>';
        header('Location: orders.php'); // Redirect to the orders list page
        exit();
    }

    // --- Step 2: Fetch the order items for the found order ---
    // Join 'order_items' with 'products' to get item details like name, image, and price
    $stmt = $conn->prepare("
        SELECT 
            oi.product_id, 
            oi.quantity, 
            p.name, 
            p.image_url, 
            p.price 
        FROM 
            order_items oi
        INNER JOIN 
            products p ON oi.product_id = p.id
        WHERE 
            oi.order_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Prepare statement for items failed: " . $conn->error);
    }
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order_items = $result->fetch_all(MYSQLI_ASSOC); // Fetch all item rows
    $stmt->close();

    // --- Step 3: Calculate the subtotal ---
    foreach ($order_items as $item) {
        // Ensure 'quantity' column exists in your order_items table and 'price' in products table
        $subtotal += ($item['price'] * $item['quantity']);
    }

} catch (Exception $e) {
    // Log the error for debugging (check your web server's error logs)
    error_log("Error fetching order details in order_confirmation.php: " . $e->getMessage());
    $_SESSION['page_message'] = '<div class="error-message">An error occurred while loading order details. Please try again later.</div>';
    header('Location: orders.php'); // Redirect on a critical error
    exit();
}

// Optional: Clear the cart after a successful order.
// This is typically done on the *checkout processing* page, not here,
// as a user might want to revisit the confirmation page without clearing their cart again.
// if (isset($_SESSION['cart'])) {
//     unset($_SESSION['cart']);
// }
?>

<!DOCTYPE html>
<html>
<head>
    <title>Order Confirmation - C2C Marketplace</title>
    <link rel="stylesheet" href="cart.css">
    <link rel="stylesheet" href="order_confirmation.css">
</head>
<body>
    <header class="header">
        <div class="header-content">
            <div class="logo">C2C marketplace</div>
            <nav>
                <ul class="nav-links">
                    <li><a href="homepage.php">Home</a></li>
                    <?php if (is_logged_in()): ?>
                        <?php if (is_buyer()): ?>
                            <li><a href="cart.php">My Cart</a></li>
                            <li><a href="orders.php">My Orders</a></li>
                        <?php endif; ?>
                        <?php if (is_seller()): ?>
                            <li><a href="dashboard.php" class="sell-button">Sell</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            </nav>
            <div class="user-actions">
                <?php if (is_logged_in()): ?>
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

    <main class="main-container confirmation-container">
        <?php if (!empty($message)) echo $message; // Display any session messages ?>

        <?php if ($order): // Only display this section if $order was successfully fetched ?>
            <div class="confirmation-header">
                <div class="success-icon">✓</div>
                <h1>Order Confirmed!</h1>
                <p class="confirmation-message">Thank you for your order. We've received your order and will process it shortly.</p>
            </div>

            <div class="order-details-card">
                <div class="order-header">
                    <h2>Order Details</h2>
                    <div class="order-meta">
                        <span class="order-number">Order #<?= htmlspecialchars($order['id']) ?></span>
                        <span class="order-date"><?= date('F j, Y \a\t g:i A', strtotime($order['order_date'])) ?></span>
                    </div>
                </div>

                <div class="order-status">
                    <span class="status-badge status-<?= strtolower(htmlspecialchars($order['status'])) ?>">
                        <?= ucfirst(htmlspecialchars($order['status'])) ?>
                    </span>
                </div>

                <?php if (!empty($order_items)): ?>
                    <div class="order-items-section">
                        <h3>Items Ordered</h3>
                        <div class="order-items-list">
                            <?php foreach ($order_items as $item): ?>
                                <div class="order-item">
                                    <div class="item-image">
                                        <?php if (!empty($item['image_url'])): ?>
                                            <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                                        <?php else: ?>
                                            <div class="no-image-placeholder">No Image</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-details">
                                        <h4 class="item-name"><?= htmlspecialchars($item['name']) ?></h4>
                                        <div class="item-meta">
                                            <span class="item-quantity">Qty: <?= htmlspecialchars($item['quantity']) ?></span>
                                            <span class="item-price">@ R<?= number_format($item['price'], 2) ?></span>
                                        </div>
                                    </div>
                                    <div class="item-total">
                                        R<?= number_format($item['price'] * $item['quantity'], 2) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="order-items-section">
                        <p>No items found for this order.</p>
                    </div>
                <?php endif; ?>

                <div class="order-summary">
                    <h3>Order Summary</h3>
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span>R<?= number_format($subtotal, 2) ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Delivery Fee:</span>
                        <span>R<?= number_format($delivery_fee, 2) ?></span>
                    </div>
                    <div class="summary-row total-row">
                        <span>Total:</span>
                        <span>R<?= number_format($subtotal + $delivery_fee, 2) ?></span>
                    </div>
                </div>

                <div class="shipping-info">
                    <h3>Shipping Information</h3>
                    <div class="shipping-address">
                        <p><strong><?= htmlspecialchars($order['shipping_full_name']) ?></strong></p>
                        <p><?= htmlspecialchars($order['shipping_street_address']) ?></p>
                        <p><?= htmlspecialchars($order['shipping_city']) ?>, <?= htmlspecialchars($order['shipping_province']) ?> <?= htmlspecialchars($order['shipping_postal_code']) ?></p>
                        <p>Phone: <?= htmlspecialchars($order['shipping_phone']) ?></p>
                    </div>
                </div>

                <div class="payment-info">
                    <h3>Payment Method</h3>
                    <p><?= htmlspecialchars($order['payment_method']) ?></p>
                    <?php if ($order['payment_method'] == 'EFT'): ?>
                        <div class="payment-instructions">
                            <h4>Payment Instructions:</h4>
                            <p>Please make payment via EFT to the following account:</p>
                            <div class="bank-details">
                                <p><strong>Bank:</strong> First National Bank</p>
                                <p><strong>Account Name:</strong> C2C Marketplace</p>
                                <p><strong>Account Number:</strong> 1234567890</p>
                                <p><strong>Branch Code:</strong> 250655</p>
                                <p><strong>Reference:</strong> ORDER<?= htmlspecialchars($order['id']) ?></p>
                            </div>
                            <p class="payment-note">Please use ORDER<?= htmlspecialchars($order['id']) ?> as your payment reference and email proof of payment to orders@c2cmarketplace.com</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="next-steps">
                <h3>What happens next?</h3>
                <div class="steps">
                    <div class="step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h4>Payment Processing</h4>
                            <p>We'll process your payment and confirm your order.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h4>Order Preparation</h4>
                            <p>Your items will be prepared and packaged for shipping.</p>
                        </div>
                    </div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h4>Shipping</h4>
                            <p>We'll ship your order and provide tracking information.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <a href="orders.php" class="btn btn-primary">View All Orders</a>
                <a href="homepage.php" class="btn btn-secondary">Continue Shopping</a>
                <button onclick="window.print()" class="btn btn-outline">Print Order</button>
            </div>

        <?php else: // This block executes if $order is null (order not found or invalid ID) ?>
            <div class="error-state">
                <h2>Order Not Found</h2>
                <p>We couldn't find the order you're looking for, or you don't have permission to view it.</p>
                <a href="orders.php" class="btn btn-primary">View Your Orders</a>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?= date('Y'); ?> C2C Marketplace. All rights reserved.</p>
            <nav>
                <ul>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                    <li><a href="#">Contact Us</a></li>
                </ul>
            </nav>
        </div>
    </footer>

    <script>
        // Auto-scroll to top on page load
        window.addEventListener('load', function() {
            window.scrollTo(0, 0);
        });

        // Add some interactive feedback for buttons (especially print)
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function(e) {
                if (this.textContent.includes('Print')) {
                    e.preventDefault(); // Prevent default link behavior if it's an anchor
                    window.print();
                }
            });
        });
    </script>
</body>
</html>