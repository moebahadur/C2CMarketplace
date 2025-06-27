<?php
require_once 'config.php';

// Check if user is logged in and is a buyer
if (!is_logged_in() || !is_buyer()) {
    header('Location: index.php');
    exit();
}

// Check if order success data exists
if (!isset($_SESSION['order_success'])) {
    header('Location: cart.php');
    exit();
}

$order_data = $_SESSION['order_success'];
unset($_SESSION['order_success']); // Clear the session data after use

// Fetch order details from database
$stmt = $pdo->prepare("
    SELECT o.*, 
           COUNT(oi.id) as item_count,
           GROUP_CONCAT(CONCAT(p.name, ' (', oi.quantity, ')') SEPARATOR ', ') as items_summary
    FROM orders o 
    LEFT JOIN order_items oi ON o.id = oi.order_id 
    LEFT JOIN products p ON oi.id = p.id 
    WHERE o.id = ? AND o.user_id = ?
    GROUP BY o.id
");
$stmt->execute([$order_data['order_id'], $_SESSION['user']['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: cart.php');
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Order Confirmed - C2C Marketplace</title>
    <link rel="stylesheet" href="cart.css">
    <style>
        .success-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
        }
        
        .success-icon {
            font-size: 4em;
            color: #28a745;
            margin-bottom: 20px;
        }
        
        .success-title {
            color: #28a745;
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .success-subtitle {
            color: #666;
            font-size: 1.2em;
            margin-bottom: 30px;
        }
        
        .order-details-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 30px;
            margin: 30px 0;
            text-align: left;
        }
        
        .order-details-title {
            font-size: 1.4em;
            color: #333;
            margin-bottom: 20px;
            text-align: center;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding: 8px 0;
        }
        
        .detail-row:nth-child(odd) {
            background: rgba(0, 123, 255, 0.05);
            margin: 0 -15px;
            padding: 8px 15px;
        }
        
        .detail-label {
            font-weight: bold;
            color: #555;
        }
        
        .detail-value {
            color: #333;
        }
        
        .total-amount {
            font-size: 1.3em;
            font-weight: bold;
            color: #007bff;
            border-top: 2px solid #007bff;
            padding-top: 15px;
            margin-top: 15px;
        }
        
        .payment-method {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
            text-align: center;
        }
        
        .payment-method-title {
            font-weight: bold;
            color: #1976d2;
            margin-bottom: 5px;
        }
        
        .shipping-info {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .shipping-title {
            font-weight: bold;
            color: #856404;
            margin-bottom: 10px;
        }
        
        .shipping-address {
            color: #333;
            line-height: 1.6;
        }
        
        .action-buttons {
            margin-top: 30px;
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #545b62;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #1e7e34;
            color: white;
        }
        
        .next-steps {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
            text-align: left;
        }
        
        .next-steps-title {
            font-weight: bold;
            color: #0c5460;
            margin-bottom: 15px;
            font-size: 1.2em;
        }
        
        .next-steps ul {
            color: #0c5460;
            line-height: 1.8;
            margin: 0;
            padding-left: 20px;
        }
        
        .contact-info {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
        }
        
        .contact-info p {
            margin: 5px 0;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .success-container {
                padding: 15px;
            }
            
            .success-title {
                font-size: 2em;
            }
            
            .success-icon {
                font-size: 3em;
            }
            
            .order-details-card {
                padding: 20px;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
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
                    <?php if (is_logged_in()): ?>
                        <?php if (is_buyer()): ?>
                            <li><a href="cart.php">My Cart</a></li>
                            <li><a href="orders.php">My Orders</a></li>
                        <?php endif; ?>
                        <?php if (is_seller()): ?>
                            <li><a href="dashboard.php" class="sell-button">Sell</a></li>
                        <?php endif; ?>
                        <li><a href="#">Messages</a></li>
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

    <main class="main-container success-container">
        <div class="success-icon">✓</div>
        <h1 class="success-title">Order Confirmed!</h1>
        <p class="success-subtitle">Thank you for your purchase. Your order has been successfully placed.</p>
        
        <div class="order-details-card">
            <h2 class="order-details-title">Order Details</h2>
            
            <div class="detail-row">
                <span class="detail-label">Order Number:</span>
                <span class="detail-value">#<?= htmlspecialchars($order['id']) ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Order Date:</span>
                <span class="detail-value"><?= date('F j, Y \a\t g:i A', strtotime($order['order_date'])) ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Items Ordered:</span>
                <span class="detail-value"><?= htmlspecialchars($order['items_summary']) ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Subtotal:</span>
                <span class="detail-value">R<?= number_format($order['total_amount'] - $order['delivery_fee'], 2) ?></span>
            </div>
            
            <div class="detail-row">
                <span class="detail-label">Delivery Fee:</span>
                <span class="detail-value">R<?= number_format($order['delivery_fee'], 2) ?></span>
            </div>
            
            <div class="detail-row total-amount">
                <span class="detail-label">Total Amount:</span>
                <span class="detail-value">R<?= number_format($order['total_amount'], 2) ?></span>
            </div>
        </div>
        
        <div class="payment-method">
            <div class="payment-method-title">Payment Method</div>
            <div><?= $order['payment_method'] == 'cheque' ? 'Bank Cheque' : 'Credit Card' ?></div>
        </div>
        
        <div class="shipping-info">
            <div class="shipping-title">Shipping Address</div>
            <div class="shipping-address">
                <strong><?= htmlspecialchars($order['shipping_full_name']) ?></strong><br>
                <?= htmlspecialchars($order['shipping_street_address']) ?><br>
                <?= htmlspecialchars($order['shipping_city']) ?>, <?= htmlspecialchars($order['shipping_province']) ?> <?= htmlspecialchars($order['shipping_postal_code']) ?><br>
                Tel: <?= htmlspecialchars($order['shipping_phone']) ?>
            </div>
        </div>
        
        <div class="next-steps">
            <div class="next-steps-title">What happens next?</div>
            <ul>
                <li><strong>Order Processing:</strong> Your order is being processed and will be prepared for shipment.</li>
                <li><strong>Payment:</strong> 
                    <?php if ($order['payment_method'] == 'cheque'): ?>
                        You will receive instructions for cheque payment via email.
                    <?php else: ?>
                        Your credit card will be charged once the order is confirmed.
                    <?php endif ?>
                </li>
                <li><strong>Shipping:</strong> You will receive a tracking number once your order has been dispatched.</li>
                <li><strong>Delivery:</strong> Your order will be delivered to the address provided within 3-7 business days.</li>
                <li><strong>Updates:</strong> We'll send you email updates about your order status.</li>
            </ul>
        </div>
        
        <div class="contact-info">
            <p><strong>Need help?</strong></p>
            <p>Contact our customer service team if you have any questions about your order.</p>
            <p>Email: support@c2cmarketplace.co.za | Phone: 0800 123 456</p>
        </div>
        
        <div class="action-buttons">
            <a href="orders.php" class="btn btn-primary">View My Orders</a>
            <a href="homepage.php" class="btn btn-secondary">Continue Shopping</a>
            <button onclick="window.print()" class="btn btn-success">Print Receipt</button>
        </div>
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
        // Auto-redirect after 2 minutes if user doesn't interact
        let autoRedirectTimer = setTimeout(function() {
            if (confirm('Would you like to continue shopping?')) {
                window.location.href = 'homepage.php';
            }
        }, 120000); // 2 minutes

        // Clear timer if user interacts with the page
        document.addEventListener('click', function() {
            clearTimeout(autoRedirectTimer);
        });

        // Add some celebration animation
        document.addEventListener('DOMContentLoaded', function() {
            const icon = document.querySelector('.success-icon');
            icon.style.transform = 'scale(0)';
            icon.style.transition = 'transform 0.5s ease-out';
            
            setTimeout(function() {
                icon.style.transform = 'scale(1)';
            }, 200);
        });
    </script>
</body>
</html>