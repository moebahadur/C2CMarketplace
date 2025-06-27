<?php
require_once 'config.php'; // Include the config file (ensures $conn is available here)

// Start session if not already started (config.php already does this, but good to ensure)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in

if (!is_logged_in()) {
    // If not, redirect to index.php (login/register page)
    header('Location: index.php');
    exit();
}

// Initialize cart if it doesn't exist (though it should from cart.php)
// if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
//     $_SESSION['page_message'] = '<div class="error-message">Your cart is empty. Please add products first.</div>';
//     header('Location: cart.php'); // Redirect if cart is empty
//     exit();
// }

$message = ''; // For success/error messages
$cart_products = [];
$cart_total = 0;
$delivery_fee = 100.00;

// Retrieve message from session if redirected (e.g., from cart.php after adding item)
// if (isset($_SESSION['page_message'])) {
//     $message = $_SESSION['page_message'];
//     unset($_SESSION['page_message']);
// }


// Better input sanitization
function sanitizeInput($input) {
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

// Enhanced validation with better error messages
function validateShippingAddress($address) {
    $errors = [];
    $required_fields = [
        'full_name' => 'Full name',
        'phone' => 'Phone number',
        'street_address' => 'Street address',
        'city' => 'City',
        'province' => 'Province',
        'postal_code' => 'Postal code'
    ];

    foreach ($required_fields as $field => $label) {
        if (empty($address[$field])) {
            $errors[] = "$label is required.";
        }
    }

    // Validate phone number
    if (!empty($address['phone']) && !preg_match('/^(\+27|0)[6-8][0-9]{8}$/', $address['phone'])) {
        $errors[] = "Phone number must be a valid South African number (e.g., 0821234567).";
    }

    // Validate postal code
    if (!empty($address['postal_code']) && !preg_match('/^[0-9]{4}$/', $address['postal_code'])) {
        $errors[] = "Postal code must be exactly 4 digits.";
    }

    // Validate province
    $valid_provinces = [
        'Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal',
        'Limpopo', 'Mpumalanga', 'Northern Cape', 'North West', 'Western Cape'
    ];
    if (!empty($address['province']) && !in_array($address['province'], $valid_provinces)) {
        $errors[] = "Please select a valid province.";
    }

    return $errors;
}
function placeOrder($conn, $total_with_delivery, $delivery_fee,  $shipping_address, $payment_method) {
    $stmt = $conn-> prepare ("SELECT id, user_id, product_id, quantity, date_added FROM cart_items WHERE user_id = ?");
    $stmt ->bind_param("i", $_SESSION['user']['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $cart_products =$result->fetch_all(MYSQLI_ASSOC);
    if (empty($cart_products)) {
        $message = '<div class="error-message">Your cart is empty. Please add products before checking out.</div>';
    } else {
        $total = 0;
        $order_items_data = []; // Store product data for order_items insertion

        // Fetch product details and calculate total
        // Add this before the foreach loop for debugging
        
        foreach ($cart_products as $cart_product) {
            $id = htmlspecialchars($cart_product['id']); // Always sanitize output!
            $user_id = htmlspecialchars($cart_product['user_id']);
            $product_id = $cart_product['product_id'];
            $quantity = $cart_product['quantity'];
            $date_added = $cart_product['date_added'];
            
            $stmt = $conn->prepare("SELECT id, price, stock_quantity FROM products WHERE id=?");
            if ($stmt === false) {
                error_log("Error preparing product fetch for checkout: " . $conn->error);
                $message = '<div class="error-message">Database error. Please try again.</div>';
                break; // Exit loop on error
            }
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $prod = $result->fetch_assoc();


            if ($prod['stock_quantity'] >= $quantity) {
                $item_price = $prod['price']; // Price at the time of checkout
                $total += $item_price * $quantity;
                $order_items_data[] = [
                    'id' => $product_id,
                    'quantity' => $quantity,
                    'price' => $item_price,
                    'current_stock' => $prod['stock_quantity']
                ];
            } else {
                $message = '<div class="error-message">Product "' . htmlspecialchars($prod['name'] ?? $cart_product) . '" is out of stock or requested quantity is too high.</div>';
                break; // Stop checkout if any item is problematic
            }
        }
            // $message= '';
        if (empty($message)) { // Only proceed if no errors during product check

            try {
                $stmt_order = $conn->prepare("
                    INSERT INTO orders (
                        user_id, total_amount, delivery_fee, order_date, status,
                        shipping_full_name, shipping_phone, shipping_street_address,
                        shipping_city, shipping_province, shipping_postal_code,
                        payment_method
                    ) VALUES (?, ?, ?, NOW(), 'pending', ?, ?, ?, ?, ?, ?, ?);
                ");
                

                $stmt_order->bind_param("iddsssssss",
                    $user_id,
                    $total_with_delivery,
                    $delivery_fee,
                    $shipping_address['full_name'],
                    $shipping_address['phone'],
                    $shipping_address['street_address'],
                    $shipping_address['city'],
                    $shipping_address['province'],
                    $shipping_address['postal_code'],
                    $payment_method
                );
                // Insert each cart item as a separate order record
                $stmt_order->execute();
                $order_id = $conn->insert_id;

                $stmt_order = $conn->prepare("INSERT INTO orders (user_id, order_date, status, total_amount) VALUES (?, NOW(), 'pending', ?)");
                $stmt_order->bind_param("id", $_SESSION['user']['id'], $total);
                
                $stmt_order_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity ) VALUES (?, ?, ?)");
                $stmt_stock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                if ($stmt_stock === false) {
                    throw new Exception("Failed to prepare stock update statement: " . $conn->error);
                }
                foreach ($order_items_data as $item) {
                    // Insert order record for this item with quantity
                    $stmt_order_item->bind_param("iii", $order_id, $item['id'], $item['quantity']);
                    $stmt_order_item->execute();

                    $stmt_stock->bind_param("ii",
                        $item['quantity'],
                        $item['id']
                    );
                    $stmt_stock->execute();

                    if ($stmt_stock->affected_rows === 0) {
                        throw new Exception("Failed to update stock for product ID: " . htmlspecialchars($item['id']) . ". Possibly insufficient stock or product not found.");
                    }
                    
                }

               
                $stmt_order->close();
                $stmt_stock->close();
                $stmt_order_item->close();
                
                return $order_id;

            } catch (Exception $e) {
                $conn->rollback();
                error_log("Checkout error: " . $e->getMessage());
                $_SESSION['page_message'] = '<div class="error-message">PLACING ORDER FAILED: ' . htmlspecialchars($e->getMessage()) . '</div>';
                return $order_id;
            }
        }
    }
}
// Better stock validation with atomic operations using MySQLi
function validateAndProcessOrder($conn, $cart_items, $user_id, $shipping_address, $payment_method, $delivery_fee) {
    // Start transaction
    $conn->autocommit(FALSE);

    try {
        $order_items_data = [];
        $total_amount = 0;

        // Lock products and validate stock
        foreach ($cart_items as $product_id => $quantity) {
            // Prepare statement to select product and lock the row
            $stmt = $conn->prepare("SELECT id, name, price, stock_quantity FROM products WHERE id = ? FOR UPDATE");
            if ($stmt === false) {
                throw new Exception("Failed to prepare product selection statement: " . $conn->error);
            }
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            $stmt->close(); // Close statement immediately after use

            if (!$product) {
                throw new Exception("Product with ID " . htmlspecialchars($product_id) . " not found.");
            }

            if ($product['stock_quantity'] < $quantity) {
                throw new Exception("Insufficient stock for " . htmlspecialchars($product['name']) .
                                     ". Available: {$product['stock_quantity']}, Requested: {$quantity}");
            }

            $subtotal = $product['price'] * $quantity;
            $total_amount += $subtotal;

            $order_items_data[] = [
                'product_id' => $product_id,
                'name' => $product['name'], // Storing name just for the error message, not DB insert
                'quantity' => $quantity,
                'price' => $product['price']
            ];
        }

        $total_with_delivery = $total_amount + $delivery_fee;

        $order_id = placeOrder($conn, $total_with_delivery, $delivery_fee, $shipping_address, $payment_method);
        $conn->commit(); // Commit the transaction
        return ['success' => true, 'order_id' => $order_id, 'total' => $total_with_delivery];

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on error
        error_log("Order processing error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        $conn->autocommit(TRUE); // Re-enable autocommit
    }
}


// Fetch products in cart for display on the checkout page
// This block is for displaying the summary to the user before they confirm
// NOTE: This logic appears to be fetching items from *past orders* rather than the *current cart*.
// If this is meant to display current cart contents, you should fetch from `cart_items` table
// similar to how it's done in cart.php, and not from `orders` and `order_items`.
$stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.image_url, ci.quantity AS quantity_in_cart, p.stock_quantity FROM products p INNER JOIN cart_items ci ON p.id = ci.product_id WHERE ci.user_id=?");
$stmt->bind_param("i" , $_SESSION['user']['id']);
$stmt->execute();
$result = $stmt->get_result();

while ($prod = $result->fetch_assoc()) {
    // The quantity_in_cart here comes directly from cart_items
    $prod['subtotal'] = $prod['price'] * $prod['quantity_in_cart'];
    $cart_total += $prod['subtotal'];
    $cart_products[] = $prod;
}
// Removed the old foreach loop that was fetching from `orders`
// if (!empty($orders)) {
//     $product_ids = [];
//     foreach($orders as $order) {
//         $stmt = $conn->prepare("SELECT id, name, price, image_url, stock_quantity FROM products WHERE id = ? ");
//         $stmt->bind_param("i" , $order['product_id']);
//         $stmt->execute();
//         $result = $stmt->get_result();
//         $prod = $result-> fetch_assoc();

//         if ($prod['stock_quantity'] < $order['quantity']) {
//             $message .= '<div class="error-message">Warning: Stock for "' . htmlspecialchars($prod['name']) . '" is now ' . $prod['stock_quantity'] . '. Your cart quantity was ' . $quantity_in_cart . '. Please adjust.</div>';
//         }
//         $prod['quantity_in_cart'] = $order['quantity'];
//         $prod['subtotal'] = $prod['price'] * $order['quantity'];
//         $cart_total += $prod['subtotal'];
//         $cart_products[] = $prod;
//     }
// }

$total_with_delivery = $cart_total + $delivery_fee;


// --- Handle Checkout Form Submission ---
if (isset($_POST['place_order'])) {
    $shipping_address = [
        'full_name' => sanitizeInput($_POST['full_name'] ?? ''),
        'phone' => sanitizeInput($_POST['phone'] ?? ''),
        'street_address' => sanitizeInput($_POST['street_address'] ?? ''),
        'city' => sanitizeInput($_POST['city'] ?? ''),
        'province' => sanitizeInput($_POST['province'] ?? ''),
        'postal_code' => sanitizeInput($_POST['postal_code'] ?? '')
    ];
    $payment_method = sanitizeInput($_POST['payment_method'] ?? '');

    $validation_errors = validateShippingAddress($shipping_address);

    if (empty($validation_errors) && !empty($payment_method)) {
        // All server-side validation passed, proceed with order processing
        $user_id = $_SESSION['user']['id']; // Ensure user ID is available from session

        // This `cart_products` here should be the actual items currently in the cart for checkout,
        // not the `$cart_products` array generated from the display logic above which might be stale or incorrect.
        // It's better to refetch them securely here or pass them from cart.php to avoid discrepancies.
        // For now, assuming $cart_products at this point correctly represents items from the cart_items table.
        // A more robust solution might fetch current cart items again here from the DB before passing.

        // Re-fetch cart items just before processing the order to ensure real-time accuracy and prevent tampering
        $current_cart_items_for_processing = [];
        $stmt_fetch_cart = $conn->prepare("SELECT product_id, quantity FROM cart_items WHERE user_id = ?");
        $stmt_fetch_cart->bind_param("i", $user_id);
        $stmt_fetch_cart->execute();
        $result_fetch_cart = $stmt_fetch_cart->get_result();
        while($row = $result_fetch_cart->fetch_assoc()) {
            $current_cart_items_for_processing[$row['product_id']] = $row['quantity'];
        }
        $stmt_fetch_cart->close();

        if (empty($current_cart_items_for_processing)) {
            $message = '<div class="error-message">Your cart is empty. Please add products before checking out.</div>';
        } else {
            $checkout_result = validateAndProcessOrder($conn, $current_cart_items_for_processing, $user_id, $shipping_address, $payment_method, $delivery_fee);

            if ($checkout_result['success']) { // Access 'success' key
                // Delete cart items after successful order processing
                $stmt_delete_cart = $conn->prepare("DELETE FROM cart_items WHERE user_id = ?");
                $stmt_delete_cart->bind_param("i", $user_id);
                $stmt_delete_cart->execute();
                $stmt_delete_cart->close();

                $_SESSION['page_message'] = '<div class="success-message">Order placed successfully  ! Your order ID is: #' . $checkout_result['order_id'] . '. Total: R' . number_format($checkout_result['total'], 2) . '</div>';
                header('Location: order_confirmation.php?order_id=' . $checkout_result['order_id']); // Use order_id from result
                exit();
            } else {
                $message = '<div class="error-message">Checkout failed: ' . htmlspecialchars($checkout_result['error']) . '</div>';
            }
        }
    } else {
        // Display validation errors
        $message = '<div class="error-message">Please correct the following:<ul>';
        foreach ($validation_errors as $error) {
            $message .= '<li>' . htmlspecialchars($error) . '</li>';
        }
        if (empty($payment_method)) {
             $message .= '<li>Please select a payment method.</li>';
        }
        $message .= '</ul></div>';
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Checkout</title>
    <link rel="stylesheet" href="cart.css">
    <style>
        /* Add some basic styling for error messages and form elements */
        .error-message { color: red; margin-bottom: 10px; }
        .success-message { color: green; margin-bottom: 10px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input[type="text"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .form-group input.error, .form-group select.error {
            border-color: red;
        }
        .radio-group label {
            display: inline-block;
            margin-right: 15px;
        }
        .checkout-summary {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            margin-top: 20px;
            border-radius: 5px;
        }
        .checkout-summary h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .checkout-summary p {
            margin-bottom: 5px;
        }
        .checkout-summary .total-price {
            font-size: 1.2em;
            font-weight: bold;
            text-align: right;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #eee;
        }
        .place-order-btn {
            background-color: #28a745;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1.1em;
            width: 100%;
            margin-top: 20px;
        }
        .place-order-btn:hover {
            background-color: #218838;
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

    <main class="main-container checkout-container">
        <h2 class="cart-title">Checkout</h2>
        <?php if (!empty($message)) echo $message; ?>

        <?php if (!empty($cart_products)): ?>
            <div class="checkout-summary">
                <h3>Order Summary</h3>
                <?php foreach ($cart_products as $product): ?>
                    <p>
                        <?= htmlspecialchars($product['name']) ?> x <?= $product['quantity_in_cart'] ?>
                        (@ R<?= number_format($product['price'], 2) ?> each)
                        : R<?= number_format($product['subtotal'], 2) ?>
                    </p>
                <?php endforeach; ?>
                <p>Delivery Fee: R<?= number_format($delivery_fee, 2) ?></p>
                <div class="total-price">Grand Total: R<?= number_format($total_with_delivery, 2) ?></div>
            </div>

            <form id="checkout-form" method="post" action="checkout.php">
                <h3>Shipping Information</h3>
                <div class="form-group">
                    <label for="full_name">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($shipping_address['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number:</label>
                    <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($shipping_address['phone'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="street_address">Street Address:</label>
                    <input type="text" id="street_address" name="street_address" value="<?= htmlspecialchars($shipping_address['street_address'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="city">City:</label>
                    <input type="text" id="city" name="city" value="<?= htmlspecialchars($shipping_address['city'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="province">Province:</label>
                    <select id="province" name="province" required>
                        <option value="">Select Province</option>
                        <?php
                        $provinces = ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'Northern Cape', 'North West', 'Western Cape'];
                        foreach ($provinces as $prov) {
                            $selected = ($shipping_address['province'] ?? '') == $prov ? 'selected' : '';
                            echo "<option value=\"$prov\" $selected>$prov</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="postal_code">Postal Code:</label>
                    <input type="text" id="postal_code" name="postal_code" value="<?= htmlspecialchars($shipping_address['postal_code'] ?? '') ?>" required maxlength="4">
                </div>

                <h3>Payment Method</h3>
                <div class="form-group radio-group">
                    <label><input type="radio" name="payment_method" value="EFT" <?= (isset($payment_method) && $payment_method == 'EFT') ? 'checked' : '' ?>> EFT (Electronic Fund Transfer)</label><br>
                    <label><input type="radio" name="payment_method" value="Credit Card" <?= (isset($payment_method) && $payment_method == 'Credit Card') ? 'checked' : '' ?>> Credit Card (via secure gateway)</label>
                    </div>

                <button type="submit" name="place_order" class="place-order-btn">Place Order</button>
            </form>

        <?php else: ?>
            <p class="empty-cart-message">Your cart is empty. Please return to the <a href="homepage.php">homepage</a> to add items.</p>
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
    // Your client-side validation script
    document.getElementById('checkout-form').addEventListener('submit', function(e) {
        const errors = [];
        const errorDiv = document.createElement('div');
        errorDiv.classList.add('error-message');
        const mainContainer = document.querySelector('.main-container');

        // Clear previous errors
        const existingErrorDiv = mainContainer.querySelector('.error-message');
        if (existingErrorDiv) {
            existingErrorDiv.remove();
        }
        document.querySelectorAll('.form-group input.error, .form-group select.error').forEach(el => el.classList.remove('error'));

        // Validate required fields
        const requiredFields = ['full_name', 'phone', 'street_address', 'city', 'province', 'postal_code'];
        requiredFields.forEach(field => {
            const input = document.getElementById(field);
            if (!input.value.trim()) {
                errors.push(`${input.previousElementSibling.textContent.replace(':', '')} is required.`);
                input.classList.add('error');
            } else {
                input.classList.remove('error');
            }
        });

        // Validate phone number
        const phoneInput = document.getElementById('phone');
        const phone = phoneInput.value;
        // Regex allows 0 or +27, followed by 6, 7, or 8, then 8 digits
        const phoneRegex = /^(\+27|0)[6-8][0-9]{8}$/;
        if (phone && !phoneRegex.test(phone)) {
            errors.push('Please enter a valid South African phone number (e.g., 0821234567 or +27821234567).');
            phoneInput.classList.add('error');
        }

        // Validate postal code
        const postalCodeInput = document.getElementById('postal_code');
        const postalCode = postalCodeInput.value;
        const postalRegex = /^[0-9]{4}$/;
        if (postalCode && !postalRegex.test(postalCode)) {
            errors.push('Please enter a valid 4-digit postal code.');
            postalCodeInput.classList.add('error');
        }

        // Check payment method
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
        if (!paymentMethod) {
            errors.push('Please select a payment method.');
        }

        if (errors.length > 0) {
            e.preventDefault(); // Prevent form submission
            errorDiv.innerHTML = 'Please fix the following errors:<ul>' + errors.map(err => `<li>${err}</li>`).join('') + '</ul>';
            mainContainer.prepend(errorDiv); // Display errors at the top of the main content
            window.scrollTo(0, 0); // Scroll to top to show errors
            return;
        }

        // Confirm order
        const totalElement = document.querySelector('.checkout-summary .total-price');
        let totalText = "R0.00"; // Default if total is not found
        if (totalElement) {
            const match = totalElement.textContent.match(/R([\d,]+\.\d{2})/);
            if (match) {
                totalText = match[0]; // Capture the full R value
            }
        }

        if (!confirm(`Are you sure you want to place this order for ${totalText}?`)) {
            e.preventDefault(); // Prevent form submission if user cancels
        }
    });
    </script>
</body>
</html>