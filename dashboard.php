<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

require_seller();

$sellerId = get_user_id();
$sellerName = get_user_name();
$message = ""; // Message for product add/update operations

// Fetch categories for the "Add New Product" form (still needed for optional selection)
$categories = [];
try {
    $stmtCategories = $conn->prepare("SELECT id, name FROM category ORDER BY name");
    if ($stmtCategories === false) {
        error_log("Error preparing category query: " . $conn->error);
        throw new Exception("Database error: Could not prepare category query.");
    }
    $stmtCategories->execute();
    $result = $stmtCategories->get_result();
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $stmtCategories->close();
} catch (Exception $e) {
    $message = '<div class="error-message">Error fetching categories: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Handle Add Product Form Submission
if (isset($_POST['add_product'])) {
    $productName = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = $_POST['price'];
    $stockQuantity = $_POST['stock_quantity'];
    // category_id is now optional
    $categoryId = !empty($_POST['category_id']) ? $_POST['category_id'] : null; // Set to null if empty

    // Input validation (removed category_id from the initial empty check)
    if (empty($productName) || empty($description) || empty($price) || empty($stockQuantity)) {
        $message = '<div class="error-message">Please fill in all required product fields (Name, Description, Price, Stock Quantity).</div>';
    } elseif (!is_numeric($price) || $price <= 0) {
        $message = '<div class="error-message">Price must be a positive number.</div>';
    } elseif (!is_numeric($stockQuantity) || $stockQuantity < 0) {
        $message = '<div class="error-message">Stock quantity must be a non-negative number.</div>';
    } elseif (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $message = '<div class="error-message">Please upload a product image.</div>';
    } elseif ($_FILES['product_image']['error'] !== UPLOAD_ERR_OK) {
        // Specific error messages for file uploads
        switch ($_FILES['product_image']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $message = '<div class="error-message">Uploaded file exceeds maximum allowed size.</div>';
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = '<div class="error-message">File was only partially uploaded.</div>';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $message = '<div class="error-message">Missing a temporary folder.</div>';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $message = '<div class="error-message">Failed to write file to disk.</div>';
                break;
            case UPLOAD_ERR_EXTENSION:
                $message = '<div class="error-message">A PHP extension stopped the file upload.</div>';
                break;
            default:
                $message = '<div class="error-message">Unknown error uploading image. Please try again.</div>';
                break;
        }
    } else {
        $imageFile = $_FILES['product_image'];
        $imageFileName = $imageFile['name'];

        $uploadDir = 'product_images/';
        // Ensure the upload directory exists and is writable
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                $message = '<div class="error-message">Error: Could not create upload directory. Check server permissions.</div>';
            }
        }

        if (empty($message)) { // Proceed only if directory creation was successful
            $imageFileName = uniqid() . '_' . basename($imageFileName); // Use uniqid to prevent name collisions
            $targetFilePath = $uploadDir . $imageFileName;
            $imageFileType = strtolower(pathinfo($targetFilePath, PATHINFO_EXTENSION));

            $allowTypes = array('jpg', 'png', 'jpeg', 'gif');
            if (in_array($imageFileType, $allowTypes)) {
                if ($imageFile['size'] > 5000000) { // 5MB limit
                    $message = '<div class="error-message">Image file is too large. Maximum size is 5MB.</div>';
                } else {
                    if (move_uploaded_file($imageFile['tmp_name'], $targetFilePath)) {
                        try {
                            // INSERT query now includes category_id again
                            $insertProduct = $conn->prepare("INSERT INTO products (user_id, name, description, price, image_url, stock_quantity, category_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            if ($insertProduct === false) {
                                error_log("Error preparing insert query: " . $conn->error);
                                throw new Exception("Database error: Could not prepare product insert query.");
                            }
                            // bind_param now includes category_id with 'i' type (for int or null)
                            // Note: 'i' for integer. If categoryId is NULL, MySQL will handle it if the column allows NULL.
                            $insertProduct->bind_param("issdsii", $sellerId, $productName, $description, $price, $targetFilePath, $stockQuantity, $categoryId);

                            if ($insertProduct->execute()) {
                                $_SESSION['product_add_message'] = '<div class="success-message">Product added successfully!</div>';
                                header("Location: dashboard.php");
                                exit();
                            } else {
                                error_log("Error executing insert: " . $insertProduct->error);
                                throw new Exception("Error executing insert: " . $insertProduct->error);
                            }
                            $insertProduct->close();
                        } catch (Exception $e) {
                            $message = '<div class="error-message">Error adding product: ' . htmlspecialchars($e->getMessage()) . '</div>';
                            // Remove uploaded file if database insert fails
                            if (file_exists($targetFilePath)) {
                                unlink($targetFilePath);
                            }
                        }
                    } else {
                        $message = '<div class="error-message">Error moving uploaded image. Please check file permissions of the "product_images" folder.</div>';
                    }
                }
            } else {
                $message = '<div class="error-message">Sorry, only JPG, JPEG, PNG, & GIF files are allowed for images.</div>';
            }
        }
    }
}

// Check for success message from session after redirect
if (isset($_SESSION['product_add_message'])) {
    $message = $_SESSION['product_add_message'];
    unset($_SESSION['product_add_message']); // Clear the message after displaying it
}

// Fetch Seller's Products and Sales Data
$sellerProducts = [];
$totalSalesCount = 0;

try {
    $getSellerProductsSql = "SELECT id as id, name, price, stock_quantity, sales_count, image_url FROM products WHERE user_id = ?";
    $stmt = $conn->prepare($getSellerProductsSql);
    if ($stmt === false) {
        error_log("Error preparing products query: " . $conn->error);
        throw new Exception("Database error: Could not prepare seller products query.");
    }
    $stmt->bind_param("i", $sellerId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $sellerProducts[] = $row;
        $totalSalesCount += (int)$row['sales_count'];
    }
    $stmt->close();
} catch (Exception $e) {
    $message .= "<div class='error-message'>Database error fetching seller products: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Close the database connection at the end of the script for this page
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seller Dashboard - C2C Marketplace</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">C2C marketplace (Seller)</div>
            <nav>
                <ul>
                    <li><a href="homepage.php">Home</a></li>
                    <li><a href="dashboard.php">My Dashboard</a></li>
                    <!-- <li><a href="#">Messages</a></li> -->
                </ul>
            </nav>
            <div class="user-actions">
                <button class="user-button">Hello, <?= htmlspecialchars($sellerName); ?>!</button>
                <div class="dropdown-content">
                    <a href="#">Settings</a>
                    <a href="logout.php">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <main>
        <div class="container">
            <h1>Your Seller Dashboard</h1>
            <?php if (!empty($message)) echo $message; ?>

            <h2>Add New Product</h2>
            <form action="dashboard.php" method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="product_name">Product Name:</label>
                    <input type="text" id="product_name" name="name" placeholder="Enter Product Name" value="<?= isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="product_description">Product Description:</label>
                    <textarea id="product_description" name="description" placeholder="Describe your product" required><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                </div>
                <div class="form-group">
                    <label for="product_price">Price (R):</label>
                    <input type="number" id="product_price" name="price" step="0.01" placeholder="e.g., 99.99" value="<?= isset($_POST['price']) ? htmlspecialchars($_POST['price']) : ''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="stock_quantity">Stock Quantity:</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" placeholder="Available stock" value="<?= isset($_POST['stock_quantity']) ? htmlspecialchars($_POST['stock_quantity']) : ''; ?>" required>
                </div>
                <div class="form-group">
                    <label for="product_category">Category:</label>
                    <select id="product_category" name="category_id"> <option value="">-- Select a Category --</option>
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category['id']); ?>" <?= (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="" disabled>No categories available</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="product_image">Product Image:</label>
                    <input type="file" id="product_image" name="product_image" accept="image/*" required>
                    <small>Maximum file size: 5MB. Allowed formats: JPG, JPEG, PNG, GIF</small>
                </div>
                <button type="submit" name="add_product">Add Product</button>
            </form>

            <h2>Your Listed Products</h2>
            <p>Total Sales (across all your products): <?= htmlspecialchars($totalSalesCount); ?></p>
            <div class="products-grid">
                <?php if (!empty($sellerProducts)): ?>
                    <?php foreach ($sellerProducts as $product): ?>
                        <div class="product-card">
                            <?php
                            if (!empty($product['image_url']) && file_exists($product['image_url'])):
                            ?>
                                <img src="<?= htmlspecialchars($product['image_url']); ?>" alt="<?= htmlspecialchars($product['name']); ?>">
                            <?php else: ?>
                                <img src="placeholder-image.jpg" alt="No image available">
                            <?php endif; ?>
                            <h3><?= htmlspecialchars($product['name']); ?></h3>
                            <p>Price: R <?= htmlspecialchars(number_format((float)$product['price'], 2)); ?></p>
                            <p>Stock: <?= htmlspecialchars($product['stock_quantity']); ?></p>
                            <p>Sold: <?= htmlspecialchars($product['sales_count']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-products">You have not listed any products yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>