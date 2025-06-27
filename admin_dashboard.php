<?php
// Enable error reporting for debugging - REMOVE IN PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php'; // Ensures $conn is available from config.php

// Start session if not already started (important for $_SESSION['page_message'])
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Security Check: Only allow admins
if (!function_exists('is_logged_in') || !is_logged_in() || !function_exists('is_admin') || !is_admin()) {
    $_SESSION['page_message'] = '<div class="error-message">You do not have permission to access the admin dashboard.</div>';
    header('Location: index.php');
    exit();
}

// Initialize messages array
$messages = [];

// Check for and display session messages then clear them
if (isset($_SESSION['page_message'])) {
    $messages[] = $_SESSION['page_message'];
    unset($_SESSION['page_message']);
}

// Helper function for input sanitization
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
    }
}

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'dashboard'; // Changed default to 'dashboard'

// --- User Management Logic ---
// Create user
if (isset($_POST['create_user'])) {
    $name = sanitizeInput($_POST['name']);
    $email = sanitizeInput($_POST['email']);
    $password_raw = $_POST['password'];
    $role = sanitizeInput($_POST['role']);

    if (empty($name) || empty($email) || empty($password_raw) || empty($role)) {
        $messages[] = '<div class="error-message">All fields are required to create a user.</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $messages[] = '<div class="error-message">Invalid email format.</div>';
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $messages[] = '<div class="error-message">User with this email already exists.</div>';
        } else {
            $password_hashed = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            if ($stmt === false) {
                $messages[] = '<div class="error-message">Database error during user creation: ' . $conn->error . '</div>';
            } else {
                $stmt->bind_param("ssss", $name, $email, $password_hashed, $role);
                if ($stmt->execute()) {
                    $_SESSION['page_message'] = '<div class="success-message">User created successfully!</div>';
                    header('Location: admin_dashboard.php?tab=users');
                    exit();
                } else {
                    $messages[] = '<div class="error-message">Failed to create user: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
        }
        $stmt_check->close();
    }
}

// Update user
if (isset($_POST['update_user'])) {
    $id = (int)$_POST['id'];
    $name = sanitizeInput($_POST['name']);
    $email = sanitizeInput($_POST['email']);
    $role = sanitizeInput($_POST['role']);
    $password_raw = $_POST['password'] ?? '';

    if (empty($name) || empty($email) || empty($role)) {
        $messages[] = '<div class="error-message">Name, Email, and Role are required to update a user.</div>';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $messages[] = '<div class="error-message">Invalid email format.</div>';
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt_check->bind_param("si", $email, $id);
        $stmt_check->execute();
        $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) {
            $messages[] = '<div class="error-message">This email is already used by another user.</div>';
        } else {
            $sql = "UPDATE users SET name=?, email=?, role=? WHERE id=?";
            $types = "sssi";
            $params = [$name, $email, $role, $id];

            if (!empty($password_raw)) {
                $password_hashed = password_hash($password_raw, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET name=?, email=?, password=?, role=? WHERE id=?";
                $types = "ssssi";
                $params = [$name, $email, $password_hashed, $role, $id];
            }

            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                $messages[] = '<div class="error-message">Database error during user update: ' . $conn->error . '</div>';
            } else {
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $_SESSION['page_message'] = '<div class="success-message">User updated successfully!</div>';
                    // Update session if the current user's own account was updated
                    if (function_exists('get_user_id') && $id == get_user_id()) {
                        $_SESSION['user']['name'] = $name;
                        $_SESSION['user']['email'] = $email;
                        $_SESSION['user']['role'] = $role;
                    }
                    header('Location: admin_dashboard.php?tab=users');
                    exit();
                } else {
                    $messages[] = '<div class="error-message">Failed to update user: ' . $stmt->error . '</div>';
                }
                $stmt->close();
            }
        }
        $stmt_check->close();
    }
}

// Delete user
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id_to_delete = (int)$_GET['delete'];
    if (function_exists('get_user_id') && $id_to_delete == get_user_id()) {
        $_SESSION['page_message'] = '<div class="error-message">You cannot delete your own admin account.</div>';
    } else {
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        if ($stmt === false) {
            $_SESSION['page_message'] = '<div class="error-message">Database error during user deletion: ' . $conn->error . '</div>';
        } else {
            $stmt->bind_param("i", $id_to_delete);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $_SESSION['page_message'] = '<div class="success-message">User deleted successfully!</div>';
                } else {
                    $_SESSION['page_message'] = '<div class="error-message">User not found or already deleted.</div>';
                }
            } else {
                $_SESSION['page_message'] = '<div class="error-message">Failed to delete user: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
    }
    header('Location: admin_dashboard.php?tab=users');
    exit();
}

// --- Page Management Logic ---
// Create/Update Page functionality
if (isset($_POST['create_page']) || isset($_POST['update_page'])) {
    $page_id = isset($_POST['page_id']) ? (int)$_POST['page_id'] : 0;
    $title = sanitizeInput($_POST['title']);
    $content = $_POST['content']; // Keep HTML content - CAUTION: Sanitize with HTML Purifier in production!
    $slug = sanitizeInput($_POST['slug']);
    $status = sanitizeInput($_POST['status']);
    $meta_title = sanitizeInput($_POST['meta_title'] ?? '');
    $meta_description = sanitizeInput($_POST['meta_description'] ?? '');
    $parent_id = isset($_POST['parent_id']) && !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if (empty($title) || empty($content) || empty($slug)) {
        $messages[] = '<div class="error-message">Title, Content, and URL Slug are required.</div>';
    } else {
        // Generate slug from title if not provided or slug is empty after sanitization
        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $title));
            $slug = trim($slug, '-'); // Trim leading/trailing hyphens
        }

        if (isset($_POST['create_page'])) {
            // Check if slug already exists
            $stmt_check = $conn->prepare("SELECT id FROM pages WHERE slug = ?");
            $stmt_check->bind_param("s", $slug);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $messages[] = '<div class="error-message">A page with this URL slug already exists.</div>';
            } else {
                $stmt = $conn->prepare("INSERT INTO pages (title, content, slug, status, meta_title, meta_description, parent_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                if ($stmt === false) {
                     $messages[] = '<div class="error-message">Database error preparing page creation: ' . $conn->error . '</div>';
                } else {
                    $stmt->bind_param("ssssssi", $title, $content, $slug, $status, $meta_title, $meta_description, $parent_id);
                    if ($stmt->execute()) {
                        $_SESSION['page_message'] = '<div class="success-message">Page created successfully!</div>';
                        header('Location: admin_dashboard.php?tab=pages');
                        exit();
                    } else {
                        $messages[] = '<div class="error-message">Failed to create page: ' . $stmt->error . '</div>';
                    }
                    $stmt->close();
                }
            }
            $stmt_check->close();
        } else {
            // Update existing page
            $stmt_check = $conn->prepare("SELECT id FROM pages WHERE slug = ? AND id != ?");
            $stmt_check->bind_param("si", $slug, $page_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) {
                $messages[] = '<div class="error-message">Another page with this URL slug already exists.</div>';
            } else {
                $stmt = $conn->prepare("UPDATE pages SET title=?, content=?, slug=?, status=?, meta_title=?, meta_description=?, parent_id=?, updated_at=NOW() WHERE id=?");
                if ($stmt === false) {
                    $messages[] = '<div class="error-message">Database error preparing page update: ' . $conn->error . '</div>';
                } else {
                    $stmt->bind_param("ssssssii", $title, $content, $slug, $status, $meta_title, $meta_description, $parent_id, $page_id);
                    if ($stmt->execute()) {
                        $_SESSION['page_message'] = '<div class="success-message">Page updated successfully!</div>';
                        header('Location: admin_dashboard.php?tab=pages');
                        exit();
                    } else {
                        $messages[] = '<div class="error-message">Failed to update page: ' . $stmt->error . '</div>';
                    }
                    $stmt->close();
                }
            }
            $stmt_check->close();
        }
    }
}

// Delete page
if (isset($_GET['delete_page']) && is_numeric($_GET['delete_page'])) {
    $page_id = (int)$_GET['delete_page'];
    $stmt = $conn->prepare("DELETE FROM pages WHERE id=?");
    if ($stmt === false) {
        $_SESSION['page_message'] = '<div class="error-message">Database error during page deletion: ' . $conn->error . '</div>';
    } else {
        $stmt->bind_param("i", $page_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $_SESSION['page_message'] = '<div class="success-message">Page deleted successfully!</div>';
            } else {
                $_SESSION['page_message'] = '<div class="error-message">Page not found or already deleted.</div>';
            }
        } else {
            $_SESSION['page_message'] = '<div class="error-message">Failed to delete page: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
    header('Location: admin_dashboard.php?tab=pages');
    exit();
}

// Get page for editing
$edit_page = null;
if (isset($_GET['edit_page']) && is_numeric($_GET['edit_page'])) {
    $page_id = (int)$_GET['edit_page'];
    $stmt = $conn->prepare("SELECT * FROM pages WHERE id = ?");
    $stmt->bind_param("i", $page_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_page = $result->fetch_assoc();
    $stmt->close();
}

// Get all pages for parent dropdown
$all_pages = [];
$result = $conn->query("SELECT id, title FROM pages WHERE status = 'published' ORDER BY title");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_pages[] = $row;
    }
    $result->free();
}

// --- Product Management Logic ---
$edit_product = null;
// Create Product
if (isset($_POST['create_product'])) {
    $name = sanitizeInput($_POST['name']);
    $description = $_POST['description']; // CAUTION: Sanitize with HTML Purifier in production!
    $price = (float)$_POST['price'];
    $stock_quantity = (int)$_POST['stock_quantity'];

    if (empty($name) || empty($description) || empty($price)) {
        $messages[] = '<div class="error-message">Name, Description, and Price are required to create a product.</div>';
    } elseif ($price <= 0) {
        $messages[] = '<div class="error-message">Product price must be greater than zero.</div>';
    } elseif ($stock_quantity < 0) {
        $messages[] = '<div class="error-message">Stock quantity cannot be negative.</div>';
    } else {
        $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock_quantity) VALUES (?, ?, ?, ?)");
        if ($stmt === false) {
            $messages[] = '<div class="error-message">Database error preparing product creation: ' . $conn->error . '</div>';
        } else {
            $stmt->bind_param("ssdi", $name, $description, $price, $stock_quantity);
            if ($stmt->execute()) {
                $_SESSION['page_message'] = '<div class="success-message">Product created successfully!</div>';
                header('Location: admin_dashboard.php?tab=products');
                exit();
            } else {
                $messages[] = '<div class="error-message">Failed to create product: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Update Product
if (isset($_POST['update_product'])) {
    $product_id = (int)$_POST['product_id'];
    $name = sanitizeInput($_POST['name']);
    $description = $_POST['description']; // CAUTION: Sanitize with HTML Purifier in production!
    $price = (float)$_POST['price'];
    $stock_quantity = (int)$_POST['stock_quantity'];

    if (empty($name) || empty($description) || empty($price)) {
        $messages[] = '<div class="error-message">Name, Description, and Price are required to update a product.</div>';
    } elseif ($price <= 0) {
        $messages[] = '<div class="error-message">Product price must be greater than zero.</div>';
    } elseif ($stock_quantity < 0) {
        $messages[] = '<div class="error-message">Stock quantity cannot be negative.</div>';
    } else {
        $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, stock_quantity=? WHERE id=?");
        if ($stmt === false) {
            $messages[] = '<div class="error-message">Database error preparing product update: ' . $conn->error . '</div>';
        } else {
            $stmt->bind_param("ssdii", $name, $description, $price, $stock_quantity, $product_id);
            if ($stmt->execute()) {
                $_SESSION['page_message'] = '<div class="success-message">Product updated successfully!</div>';
                header('Location: admin_dashboard.php?tab=products');
                exit();
            } else {
                $messages[] = '<div class="error-message">Failed to update product: ' . $stmt->error . '</div>';
            }
            $stmt->close();
        }
    }
}

// Delete Product
if (isset($_GET['delete_product']) && is_numeric($_GET['delete_product'])) {
    $product_id_to_delete = (int)$_GET['delete_product'];
    $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
    if ($stmt === false) {
        $_SESSION['page_message'] = '<div class="error-message">Database error during product deletion: ' . $conn->error . '</div>';
    } else {
        $stmt->bind_param("i", $product_id_to_delete);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $_SESSION['page_message'] = '<div class="success-message">Product deleted successfully!</div>';
            } else {
                $_SESSION['page_message'] = '<div class="error-message">Product not found or already deleted.</div>';
            }
        } else {
            $_SESSION['page_message'] = '<div class="error-message">Failed to delete product: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
    header('Location: admin_dashboard.php?tab=products');
    exit();
}

// Get product for editing
$edit_product = null;
if (isset($_GET['edit_product']) && is_numeric($_GET['edit_product'])) {
    $product_id = (int)$_GET['edit_product'];
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_product = $result->fetch_assoc();
    $stmt->close();
}


// --- Data Fetching for Display ---

// Fetch users for display
$users = [];
$total_users = 0;
if ($current_tab == 'users' || $current_tab == 'dashboard') {
    $result = $conn->query("SELECT id, name, email, role FROM users ORDER BY id DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $total_users = count($users);
        $result->free();
    } else {
        $messages[] = '<div class="error-message">Failed to retrieve users: ' . $conn->error . '</div>';
    }
} else {
    // If not on users tab, just get a count for the dashboard stat card
    $count_result = $conn->query("SELECT COUNT(id) AS total_count FROM users");
    if ($count_result) {
        $count_row = $count_result->fetch_assoc();
        $total_users = $count_row['total_count'];
        $count_result->free();
    } else {
        $messages[] = '<div class="error-message">Failed to get total user count: ' . $conn->error . '</div>';
    }
}

// Fetch pages for display
$pages = [];
$total_pages = 0;
if ($current_tab == 'pages' || $current_tab == 'dashboard') {
    $result = $conn->query("SELECT id, title, slug, status, parent_id, created_at, updated_at FROM pages ORDER BY created_at DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pages[] = $row;
        }
        $total_pages = count($pages);
        $result->free();
    } else {
        $messages[] = '<div class="error-message">Failed to retrieve pages: ' . $conn->error . '</div>';
    }
} else {
    // If not on pages tab, just get a count for the dashboard stat card
    $count_result = $conn->query("SELECT COUNT(id) AS total_count FROM pages");
    if ($count_result) {
        $count_row = $count_result->fetch_assoc();
        $total_pages = $count_row['total_count'];
        $count_result->free();
    } else {
        $messages[] = '<div class="error-message">Failed to get total page count: ' . $conn->error . '</div>';
    }
}


// Fetch products for display
$products = [];
$total_products = 0; // Initialize total products count

if ($current_tab == 'products' || $current_tab == 'dashboard') {
    // Corrected query: ORDER BY id DESC as 'created_at' might not exist in your products table
    $product_query = "SELECT id, name, description, price, stock_quantity FROM products ORDER BY id DESC";
    $result = $conn->query($product_query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        $total_products = count($products); // Get the count of fetched products
        $result->free();
    } else {
        $messages[] = '<div class="error-message">Failed to retrieve products: ' . $conn->error . '</div>';
    }
} else {
    // If not on products tab, just get a count for the dashboard stat card
    $count_result = $conn->query("SELECT COUNT(id) AS total_count FROM products");
    if ($count_result) {
        $count_row = $count_result->fetch_assoc();
        $total_products = $count_row['total_count'];
        $count_result->free();
    } else {
        $messages[] = '<div class="error-message">Failed to get total product count: ' . $conn->error . '</div>';
    }
}


// It's good practice to close the connection once all database operations are done
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="admin_dashboard.css">
    
</head>
<body>
<div class="container">
    <div class="header-admin">
        <h2>Admin Dashboard</h2>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <?php
    // Display messages
    foreach ($messages as $msg) {
        echo $msg;
    }
    ?>

    <div class="admin-stats">
        <div class="stat-card">
            <div class="stat-number"><?= $total_users ?></div>
            <div class="stat-label">Total Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $total_pages ?></div>
            <div class="stat-label">Total Pages</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $total_products ?></div>
            <div class="stat-label">Total Products</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">0</div> <div class="stat-label">Orders Today</div>
        </div>
    </div>

    <nav class="tab-navigation">
        <a href="?tab=dashboard" class="tab-btn <?= $current_tab == 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="?tab=users" class="tab-btn <?= $current_tab == 'users' ? 'active' : '' ?>">User Management</a>
        <a href="?tab=pages" class="tab-btn <?= $current_tab == 'pages' ? 'active' : '' ?>">Page Management</a>
        <a href="?tab=products" class="tab-btn <?= $current_tab == 'products' ? 'active' : '' ?>">Product Moderation</a>
        <a href="?tab=orders" class="tab-btn <?= $current_tab == 'orders' ? 'active' : '' ?>">Order Oversight</a>
        <a href="?tab=settings" class="tab-btn <?= $current_tab == 'settings' ? 'active' : '' ?>">System Settings</a>
        <a href="?tab=analytics" class="tab-btn <?= $current_tab == 'analytics' ? 'active' : '' ?>">Analytics</a>
    </nav>

    <div class="tab-content <?= $current_tab == 'dashboard' ? 'active' : '' ?>">
        <h3>Welcome to the Admin Dashboard!</h3>
        <p>Use the tabs above to manage different aspects of your website.</p>
        <p><strong>Quick Stats:</strong></p>
        <ul>
            <li>Users: <?= $total_users ?></li>
            <li>Pages: <?= $total_pages ?></li>
            <li>Products: <?= $total_products ?></li>
            <li>Orders: (Coming Soon)</li>
        </ul>
        <p>This dashboard provides a centralized interface for managing users, content pages, products, orders, system settings, and analytics for your marketplace.</p>
    </div>

    <div class="tab-content <?= $current_tab == 'users' ? 'active' : '' ?>">
        <form method="post">
            <h3>Create User</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="userName">Name *</label>
                    <input type="text" id="userName" name="name" placeholder="User's Full Name" required>
                </div>
                <div class="form-group">
                    <label for="userEmail">Email *</label>
                    <input type="email" id="userEmail" name="email" placeholder="user@example.com" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="userPassword">Password *</label>
                    <input type="password" id="userPassword" name="password" placeholder="Min 6 characters" required>
                </div>
                <div class="form-group">
                    <label for="userRole">Role *</label>
                    <select id="userRole" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="buyer">Buyer</option>
                        <option value="seller">Seller</option>
                    </select>
                </div>
            </div>
            <button type="submit" name="create_user">Create User</button>
        </form>

        <h3>All Users</h3>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="4">No users found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <form method="post">
                            <td><input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required></td>
                            <td><input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required></td>
                            <td>
                                <select name="role">
                                    <option value="admin" <?= $user['role']=='admin'?'selected':'' ?>>Admin</option>
                                    <option value="buyer" <?= $user['role']=='buyer'?'selected':'' ?>>Buyer</option>
                                    <option value="seller" <?= $user['role']=='seller'?'selected':'' ?>>Seller</option>
                                </select>
                            </td>
                            <td class="actions">
                                <input type="hidden" name="id" value="<?= $user['id'] ?>">
                                <input type="password" name="password" placeholder="New Password (optional)">
                                <button type="submit" name="update_user">Update</button>
                                <?php if (function_exists('get_user_id') && $user['id'] != get_user_id()): ?>
                                    <a href="?delete=<?= $user['id'] ?>&tab=users" onclick="return confirm('Are you sure you want to delete user <?= htmlspecialchars($user['name']) ?>?')">Delete</a>
                                <?php endif; ?>
                            </td>
                        </form>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tab-content <?= $current_tab == 'pages' ? 'active' : '' ?>">
        <?php if ($edit_page): ?>
            <div class="page-form">
                <h3>Edit Page</h3>
                <form method="post">
                    <input type="hidden" name="page_id" value="<?= $edit_page['id'] ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Page Title *</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($edit_page['title']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>URL Slug *</label>
                            <input type="text" name="slug" value="<?= htmlspecialchars($edit_page['slug']) ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Content *</label>
                        <textarea name="content" required><?= htmlspecialchars($edit_page['content']) ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="draft" <?= $edit_page['status']=='draft'?'selected':'' ?>>Draft</option>
                                <option value="published" <?= $edit_page['status']=='published'?'selected':'' ?>>Published</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Parent Page</label>
                            <select name="parent_id">
                                <option value="">None</option>
                                <?php foreach ($all_pages as $page): ?>
                                    <?php if ($page['id'] != $edit_page['id']): // Prevent self-parent ?>
                                        <option value="<?= $page['id'] ?>" <?= $edit_page['parent_id']==$page['id']?'selected':'' ?>><?= htmlspecialchars($page['title']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>SEO Title</label>
                            <input type="text" name="meta_title" value="<?= htmlspecialchars($edit_page['meta_title'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Meta Description</label>
                            <input type="text" name="meta_description" value="<?= htmlspecialchars($edit_page['meta_description'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <button type="submit" name="update_page">Update Page</button>
                    <a href="?tab=pages" class="button-secondary">Cancel</a>
                </form>
            </div>
        <?php else: ?>
            <div class="page-form">
                <h3>Create New Page</h3>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Page Title *</label>
                            <input type="text" name="title" required>
                        </div>
                        <div class="form-group">
                            <label>URL Slug *</label>
                            <input type="text" name="slug" placeholder="auto-generated-from-title">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Content *</label>
                        <textarea name="content" required placeholder="Enter your page content here..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Parent Page</label>
                            <select name="parent_id">
                                <option value="">None</option>
                                <?php foreach ($all_pages as $page): ?>
                                    <option value="<?= $page['id'] ?>"><?= htmlspecialchars($page['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>SEO Title</label>
                            <input type="text" name="meta_title">
                        </div>
                        <div class="form-group">
                            <label>Meta Description</label>
                            <input type="text" name="meta_description">
                        </div>
                    </div>
                    
                    <button type="submit" name="create_page">Create Page</button>
                </form>
            </div>
        <?php endif; ?>

        <h3>All Pages</h3>
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pages)): ?>
                    <tr>
                        <td colspan="5">No pages found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pages as $page): ?>
                    <tr>
                        <td><?= htmlspecialchars($page['title']) ?></td>
                        <td>/<?= htmlspecialchars($page['slug']) ?></td>
                        <td><span class="page-status status-<?= $page['status'] ?>"><?= ucfirst($page['status']) ?></span></td>
                        <td><?= date('M j, Y', strtotime($page['created_at'])) ?></td>
                        <td class="actions">
                            <a href="?tab=pages&edit_page=<?= $page['id'] ?>">Edit</a>
                            <a href="?delete_page=<?= $page['id'] ?>&tab=pages" onclick="return confirm('Are you sure you want to delete page \'<?= htmlspecialchars($page['title']) ?>\'?')">Delete</a>
                            <a href="/<?= htmlspecialchars($page['slug']) ?>" target="_blank">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tab-content <?= $current_tab == 'products' ? 'active' : '' ?>">
        <?php if ($edit_product): ?>
            <div class="product-form">
                <h3>Edit Product</h3>
                <form method="post">
                    <input type="hidden" name="product_id" value="<?= $edit_product['id'] ?>">
                    
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($edit_product['name']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" required><?= htmlspecialchars($edit_product['description']) ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Price *</label>
                            <input type="number" name="price" step="0.01" min="0" value="<?= htmlspecialchars($edit_product['price']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Stock Quantity *</label>
                            <input type="number" name="stock_quantity" min="0" value="<?= htmlspecialchars($edit_product['stock_quantity']) ?>" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_product">Update Product</button>
                    <a href="?tab=products" class="button-secondary">Cancel</a>
                </form>
            </div>
        <?php else: ?>
            <div class="product-form">
                <h3>Create New Product</h3>
                <form method="post">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" placeholder="e.g., Handcrafted Leather Wallet" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" placeholder="A detailed description of the product..." required></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Price *</label>
                            <input type="number" name="price" step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>Stock Quantity *</label>
                            <input type="number" name="stock_quantity" min="0" value="1" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="create_product">Create Product</button>
                </form>
            </div>
        <?php endif; ?>

        <h3>All Products</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="5">No products found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['id']) ?></td>
                        <td><?= htmlspecialchars($product['name']) ?></td>
                        <td>R<?= number_format($product['price'], 2) ?></td>
                        <td><?= htmlspecialchars($product['stock_quantity']) ?></td>
                        <td class="actions">
                            <a href="?tab=products&edit_product=<?= $product['id'] ?>">Edit</a>
                            <a href="?delete_product=<?= $product['id'] ?>&tab=products" onclick="return confirm('Are you sure you want to delete product \'<?= htmlspecialchars($product['name']) ?>\'?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tab-content <?= $current_tab == 'orders' ? 'active' : '' ?>">
        <h3>Order Oversight</h3>
        <p>This section will allow you to:</p>
        <ul>
            <li>Monitor all transactions and orders</li>
            <li>Manage order disputes between buyers and sellers</li>
            <li>Process refunds and cancellations</li>
            <li>Track shipping and delivery status</li>
            <li>Generate order reports and analytics</li>
        </ul>
        <p><em>Order management functionality will be implemented here.</em></p>
    </div>

    <div class="tab-content <?= $current_tab == 'settings' ? 'active' : '' ?>">
        <h3>System Configuration</h3>
        <p>This section will allow you to:</p>
        <ul>
            <li>Configure payment gateway integrations</li>
            <li>Set up shipping rules and rates</li>
            <li>Manage security protocols and settings</li>
            <li>Configure email templates and notifications</li>
            <li>Adjust platform-wide settings and preferences</li>
        </ul>
        <p><em>System configuration functionality will be implemented here.</em></p>
    </div>

    <div class="tab-content <?= $current_tab == 'analytics' ? 'active' : '' ?>">
        <h3>Analytics & Reporting</h3>
        <p>This section will provide:</p>
        <ul>
            <li>Website traffic analytics and visitor insights</li>
            <li>Sales performance reports and trends</li>
            <li>User behavior analysis and engagement metrics</li>
            <li>Revenue and financial reporting</li>
            <li>Product performance and popularity metrics</li>
        </ul>
        <p><em>Analytics and reporting functionality will be implemented here.</em></p>
    </div>
</div>

<script>
// Auto-generate slug from title for pages
document.addEventListener('DOMContentLoaded', function() {
    const pageTitleInput = document.querySelector('.tab-content[data-tab="pages"] input[name="title"]');
    const pageSlugInput = document.querySelector('.tab-content[data-tab="pages"] input[name="slug"]');
    
    // Check if both elements exist and if slug is empty (for new page creation)
    if (pageTitleInput && pageSlugInput && !pageSlugInput.value) {
        pageTitleInput.addEventListener('input', function() {
            const slug = this.value
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '') // Remove special characters except spaces and hyphens
                .replace(/\s+/g, '-')        // Replace spaces with single hyphens
                .replace(/-+/g, '-')        // Replace multiple hyphens with single
                .trim('-');                  // Remove leading/trailing hyphens
            pageSlugInput.value = slug;
        });
    }

   
});
</script>

</body>
</html>