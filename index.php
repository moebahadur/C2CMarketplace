<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the config file which starts the session and sets up the DB connection
require_once 'config.php'; // This correctly includes your config.php (formerly login_register.php)

$errors = [
    'login' => '',
    'register' => ''
];
$activeForm = 'login'; // Default to login form

// --- Handle Login Form Submission ---
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $errors['login'] = "Email and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
        if ($stmt === false) {
            $errors['login'] = "Database error preparing login query: " . $conn->error;
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    // Login successful
                    $_SESSION['user'] = [
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role']
                    ];
                    if ($user['role'] === 'admin') {
                        // Redirect admin to admin_dashboard.php
                        header("Location: admin_dashboard.php");
                        exit();
                    } 
                    // Redirect all logged-in users to homepage.php
                    header("Location: homepage.php"); // <--- CHANGED THIS LINE
                    exit();
                } else {
                    $errors['login'] = "Invalid password.";
                }
            } else {
                $errors['login'] = "No user found with that email address.";
            }
            $stmt->close();
        }
    }
    $activeForm = 'login'; // Keep login form active if there's an error
}

// --- Handle Register Form Submission ---
if (isset($_POST['register'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Basic validation
    if (empty($name) || empty($email) || empty($password) || empty($role)) {
        $errors['register'] = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['register'] = "Invalid email format.";
    } elseif (!in_array($role, ['buyer', 'seller','admin'])) {
        $errors['register'] = "Invalid role selected.";
    } else {
        // Check if email already exists
        $stmtCheck = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if ($stmtCheck === false) {
            $errors['register'] = "Database error preparing email check: " . $conn->error;
        } else {
            $stmtCheck->bind_param("s", $email);
            $stmtCheck->execute();
            $stmtCheck->store_result();
            if ($stmtCheck->num_rows > 0) {
                $errors['register'] = "Email already registered. Please log in.";
            } else {
                // Hash the password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // Insert new user
                $stmtInsert = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
                if ($stmtInsert === false) {
                    $errors['register'] = "Database error preparing registration query: " . $conn->error;
                } else {
                    $stmtInsert->bind_param("ssss", $name, $email, $hashed_password, $role);
                    if ($stmtInsert->execute()) {
                        $_SESSION['message'] = '<div class="success-message">Registration successful! Please log in.</div>';
                        // After successful registration, redirect to clear POST data and show success message
                        header("Location: index.php");
                        exit();
                    } else {
                        $errors['register'] = "Error registering user: " . $stmtInsert->error;
                    }
                    $stmtInsert->close();
                }
            }
            $stmtCheck->close();
        }
    }
    $activeForm = 'register'; // Keep register form active if there's an error
}

// Display success message if redirected from successful registration
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']); // Clear the message after displaying it

// Helper function to display errors
function showError($error) {
    if (!empty($error)) {
        echo '<div class="error-message">' . htmlspecialchars($error) . '</div>';
    }
}

// Close the database connection since this is the end of this script's primary database use
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>C2C Marketplace Login/Register</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <h1 class="marketplace-title">WELCOME TO C2C MARKETPLACE</h1>
    <div class="container">
        <?php if (!empty($message)) echo $message; ?>

        <div class="form-box <?= ($activeForm === 'login' && empty($errors['register'])) || (!isset($_POST['login']) && !isset($_POST['register']) && !isset($_GET['form'])) ? 'active' : ''; ?>" id="login-form">
            <form action="index.php" method="post">
                <h2>Login</h2>
                <?php showError($errors['login']); ?>
                <input type="email" name="email" placeholder="Email" required value="<?= isset($_POST['email']) && $activeForm === 'login' ? htmlspecialchars($_POST['email']) : ''; ?>">
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="login">Login</button>
                <p>Don't have an account? <a href="#" onclick="showForm('register-form')">Register</a></p>
            </form>
        </div>

        <div class="form-box <?= ($activeForm === 'register' && empty($errors['login'])) || (isset($_GET['form']) && $_GET['form'] === 'register') ? 'active' : ''; ?>" id="register-form">
            <form action="index.php" method="post">
                <h2>Register</h2>
                <?php showError($errors['register']); ?>
                <input type="text" name="name" placeholder="Name" required value="<?= isset($_POST['name']) && $activeForm === 'register' ? htmlspecialchars($_POST['name']) : ''; ?>">
                <input type="email" name="email" placeholder="Email" required value="<?= isset($_POST['email']) && $activeForm === 'register' ? htmlspecialchars($_POST['email']) : ''; ?>">
                <input type="password" name="password" placeholder="Password" required>
                <select name="role" required>
                    <option value="">--Select Role--</option>
                    <option value="buyer" <?= (isset($_POST['role']) && $_POST['role'] === 'buyer' && $activeForm === 'register') ? 'selected' : ''; ?>>Buyer</option>
                    <option value="seller" <?= (isset($_POST['role']) && $_POST['role'] === 'seller' && $activeForm === 'register') ? 'selected' : ''; ?>>Seller</option>

                     
                </select>
                <button type="submit" name="register">Register</button>
                <p>Already have an account? <a href="#" onclick="showForm('login-form')">Login</a></p>
            </form>
        </div>
    </div>

    <script>
        function showForm(formId) {
            const loginForm = document.getElementById('login-form');
            const registerForm = document.getElementById('register-form');

            // Reset active classes
            loginForm.classList.remove('active');
            registerForm.classList.remove('active');

            // Add active class to the selected form
            if (formId === 'login-form') {
                loginForm.classList.add('active');
            } else {
                registerForm.classList.add('active');
            }
        }

        // Initialize active form based on PHP's $activeForm variable or URL parameter
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            let initialActiveFormId = '<?= $activeForm; ?>-form'; // Default from PHP processing

            if (urlParams.has('form')) {
                const formFromUrl = urlParams.get('form');
                if (formFromUrl === 'login' || formFromUrl === 'register') {
                    initialActiveFormId = formFromUrl + '-form';
                }
            }
            showForm(initialActiveFormId);
        });
    </script>
</body>
</html>