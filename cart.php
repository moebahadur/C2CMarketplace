<?php

require_once 'config.php'; // Include the config file

// Check if user is logged in and is a buyer (corrected role check)
if (!is_logged_in()) {
    // If not, redirect to index.php (login/register page)
    header('Location: index.php');
    exit();
}

// Initialize cart if it doesn't exist in the session
// if (!isset($cart_products)) {
//     $cart_products = [];
// }

$message = ''; // For success/error messages



// --- Handle Add to Cart from Homepage ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id']) && !isset($_POST['update_quantity']) && !isset($_POST['remove_item']) && !isset($_POST['checkout'])) {
    $productId = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
    
    if ($productId > 0) {
        // Check if product exists and has stock
        try {
            $stmt = $conn->prepare("SELECT id, name, stock_quantity FROM products WHERE id = ?");
            $stmt->bind_param("i", $productId);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            
            $stmt = $conn-> prepare ("SELECT id, user_id, product_id, quantity, date_added FROM cart_items WHERE product_id = ? AND user_id = ?");
            $stmt ->bind_param("ii", $productId, $_SESSION['user']['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $cart_product =$result->fetch_assoc();



            if ($product && $product['stock_quantity'] > 0) {
                // Add to cart or increase quantity
                if ($cart_product) {
                    // Check if adding one more would exceed stock
                    if ($cart_product['quantity'] +1 < $product['stock_quantity']) {
                        $new_quantity= $cart_product['quantity'] +1 ;
                        $stmt = $conn-> prepare ("UPDATE cart_items SET quantity=? WHERE product_id = ? AND user_id = ?");
                        $stmt ->bind_param("iii", $new_quantity, $productId, $_SESSION['user']['id']);
                        $stmt->execute();
                        $message = '<div class="success-message">Product quantity updated in cart!</div>';
                    } else {
                        $message = '<div class="error-message">Cannot add more items. Stock limit reached.</div>';
                    }
                } else {
                    $stmt = $conn-> prepare ("INSERT INTO cart_items (quantity, product_id, user_id) VALUES (1, ?, ?)");
                    $stmt ->bind_param("ii", $productId, $_SESSION['user']['id']);
                    $stmt->execute();
                    $message = '<div class="success-message">Product added to cart!</div>';
                }
            } else {
                $message = '<div class="error-message">Product not available or out of stock.</div>';
            }
        } catch (Exception $e) {
            error_log("Add to cart error: " . $e->getMessage());
            $message = '<div class="error-message">Failed to add product to cart.</div>';
        }
        
        // Store message in session and redirect to prevent form resubmission
        $_SESSION['page_message'] = $message;
        header('Location: cart.php');
        exit();
    }
}

// --- Handle Add/Update/Remove from Cart ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (isset($_POST['update_quantity'])) {
        $productId= $_POST['update_quantity'];
        
        $quantity = filter_input(INPUT_POST, 'product_quantities_' . $productId , FILTER_SANITIZE_NUMBER_INT);

        if ($productId && $quantity !== false && $quantity >= 0) {
            if ($quantity == 0) {
                $stmt = $conn-> prepare ("DELETE FROM cart_items WHERE product_id =? AND user_id =?");
                $stmt ->bind_param("ii", $productId, $_SESSION['user']['id']);
                $stmt->execute(); 
                $message = '<div class="success-message">Product removed from cart.</div>';
            } else {
                $stmt = $conn-> prepare ("UPDATE cart_items SET quantity=? WHERE product_id = ? AND user_id = ?");
                $stmt ->bind_param("iii", $quantity, $productId, $_SESSION['user']['id']);
                $stmt->execute(); 
                $message = '<div class="success-message">Cart updated.</div>';
            }
        } else {
            $message = '<div class="error-message">Invalid product or quantity.</div>';
        }
        header('Location: cart.php'); // Redirect to prevent form re-submission
        exit();
    }

    elseif (isset($_POST['remove_item'])) {
        $productId = $_POST['remove_item'];
        if ($productId) {
            $stmt = $conn-> prepare ("DELETE FROM cart_items WHERE product_id =? AND user_id =?");
            $stmt ->bind_param("ii", $productId, $_SESSION['user']['id']);
            $stmt->execute();
            $message = '<div class="success-message">Product removed from cart.</div>';
        } else {
            $message = '<div class="error-message">Product not found in cart.</div>';
        }
        header('Location: cart.php'); // Redirect to prevent form re-submission
        exit();
    }
        $total = 0;

    // --- Handle Checkout ---
    if (isset($_POST['checkout'])) {
         header('Location: checkout.php');
        exit();
    }
   
}

// Retrieve message from session if redirected
if (isset($_SESSION['page_message'])) {
    $message = $_SESSION['page_message'];
    unset($_SESSION['page_message']); // Clear message after displaying
}

// Fetch products in cart for display
$cart_products = [];
$cart_total = 0;
$stmt = $conn->prepare("SELECT products.id, cart_items.quantity, name, price, image_url, stock_quantity FROM products INNER JOIN cart_items ON products.id = cart_items.product_id WHERE cart_items.user_id=?");

// Create the types string (all integers)
// $types = str_repeat('i', count($product_ids));
$stmt->bind_param("i", $_SESSION['user']['id']);
$stmt->execute();
$result = $stmt->get_result();

while ($prod = $result->fetch_assoc()) {
    $quantity_in_cart = $prod['quantity'];
    $prod['quantity_in_cart'] = $quantity_in_cart;
    $prod['subtotal'] = $prod['price'] * $quantity_in_cart;
    $cart_total += $prod['subtotal'];
    $cart_products[] = $prod;
}
// }
?>

<!DOCTYPE html>
<html>
<head>
    <title>Your Cart</title>
    <link rel="stylesheet" href="cart.css">
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
                        <!-- <li><a href="#">Messages</a></li> -->
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

    <main class="main-container cart-container">
        <h2 class="cart-title">Your Cart</h2>
        <?php if (!empty($message)) echo $message; ?>

        <?php if (!empty($cart_products)): ?>
            <form method="post" action="cart.php">
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Subtotal</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cart_products as $product): ?>
                            <tr>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td>
                                    <input type="number" name="product_quantities_<?= $product['id'] ?>"
                                           value="<?= $product['quantity_in_cart'] ?>" min="0" max="<?= $product['stock_quantity'] ?>"
                                           data-product-id="<?= $product['id'] ?>" class="quantity-input">
                                </td>
                                <td>R<?= number_format($product['price'], 2) ?></td>
                                <td>R<?= number_format($product['subtotal'], 2) ?></td>
                                <td>
                                    <button type="submit" name="update_quantity" class="cart-action-btn" value="<?= $product['id'] ?>">Update</button>
                                    <button type="submit" name="remove_item" class="cart-action-btn remove-btn" value="<?= $product['id'] ?>">Remove</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="checkout-section">
                    <div class="total-price">Total: R<?= number_format($cart_total, 2) ?></div>
                    <button type="submit" name="checkout" class="checkout-btn">Proceed to Checkout</button>
                </div>
            </form>
        <?php else: ?>
            <p class="empty-cart-message">Your cart is empty.</p>
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
</body>
</html>