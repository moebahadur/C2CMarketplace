<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
// Assuming is_logged_in(), is_buyer(), is_seller(), get_user_name() are defined in config.php or a file included by it.

$message = "";
$products = [];

try {
    $stmt = $conn->prepare("
        SELECT
            p.id,
            p.name,
            p.description,
            p.price,
            p.image_url,
            p.stock_quantity,
            p.sales_count,              
            c.name as category_name,
            u.name as seller_name
        FROM products p
        JOIN category c ON p.category_id = c.id
        JOIN users u ON p.user_id = u.id
        ORDER BY p.id DESC
    ");
    if ($stmt === false) {
        // Log the actual database error for better debugging
        error_log("Error preparing products query for homepage: " . $conn->error);
        throw new Exception( $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    $message = '<div class="error-message">Error fetching products: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>C2C Marketplace Home</title>
    <link rel="stylesheet" href="homepage.css">
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
                        <!-- Messages button removed as per user request -->
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
        <section class="welcome-section">
            <h1 class="welcome-title">Welcome to the C2C Marketplace!</h1>
            <p class="welcome-subtitle">Your one-stop shop for buying and selling directly with others.</p>
        </section>

        <section class="search-section">
            <form action="homepage.php" method="GET" class="search-container">
                <input type="text" name="search" placeholder="Search for products..." class="search-input">
                <button type="submit" class="search-button">Search</button>
            </form>
        </section>

        <section class="featured-section">
            <h2 class="featured-title">Latest Products</h2>
            <?php if (!empty($message)) echo $message; ?>

            <div class="products-grid">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <a href="product_details.php" class="product-image-link">
                                <?php
                                if (!empty($product['image_url']) && file_exists($product['image_url'])):
                                ?>
                                    <img src="<?= htmlspecialchars($product['image_url']); ?>" alt="<?= htmlspecialchars($product['name']); ?>">
                                <?php else: ?>
                                    <img src="placeholder-image.jpg" alt="No image available">
                                <?php endif; ?>
                            </a>
                            <div class="product-info">
                                <a href="product_details.php"class="product-title"><?= htmlspecialchars($product['name']); ?></a>
                                <p class="product-description"><?= htmlspecialchars(substr($product['description'], 0, 70)); ?>...</p>
                                <p class="product-category">Category: <?= htmlspecialchars($product['category_name']); ?></p>
                                <p class="product-seller">Seller: <?= htmlspecialchars($product['seller_name']); ?></p>
                                <p class="product-price">R <?= htmlspecialchars(number_format((float)$product['price'], 2)); ?></p>
                                <p class="product-stock">Stock: <?= htmlspecialchars($product['stock_quantity']); ?></p>
                                <p class="product-sales">Sold: <?= htmlspecialchars($product['sales_count']); ?></p> <?php if ($product['stock_quantity'] > 0): ?>
                                    <form action="cart.php" method="post" class="add-to-cart-form">
                                        <input type="hidden" name="id" value="<?= htmlspecialchars($product['id']); ?>">
                                        <button type="submit">Add to Cart</button>
                                    </form>
                                <?php else: ?>
                                    <p class="out-of-stock">Out of Stock</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-products">No products listed yet.</p>
                <?php endif; ?>
            </div>
            <div class="view-more">
                <a href="#">View All Products &rarr;</a>
            </div>
        </section>

        <section class="seller-overview">
            <p>Thinking of becoming a seller? List your items easily and reach a wide audience!</p>
            <div class="seller-stats">
                <p><strong></strong> Products Listed</p>
                <p><strong></strong> Active Sellers</p>
                <p><strong>100%</strong> Customer Satisfaction</p>
            </div>
            <?php if (!(function_exists('is_logged_in') && is_logged_in()) || !(function_exists('is_seller') && is_seller())): ?>
                <p><a href="index.php?form=register">Join us today as a Seller!</a></p>
            <?php endif; ?>
        </section>

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
