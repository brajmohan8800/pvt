<?php
// admin/redeem.php
session_start();

// --- Database Connection Configuration ---
// Copy your database credentials from bot.php or use a shared config file
$DB_CONFIG = [
    'host' => 'localhost',
    'dbname' => 'osintx', // Make sure this is correct
    'user' => 'root',     // Make sure this is correct
    'password' => ''      // Make sure this is correct
];

/**
 * Establishes a connection to the MySQL database.
 * @return PDO|null A PDO connection object or null on failure.
 */
function get_db_connection() {
    global $DB_CONFIG;
    try {
        $dsn = "mysql:host={$DB_CONFIG['host']};dbname={$DB_CONFIG['dbname']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);
        // log_info("Connected to MySQL database for admin panel");
        return $pdo;
    } catch (PDOException $e) {
        // Log error to a file in the admin directory or a common log directory
        error_log("[ADMIN][ERROR] " . date('Y-m-d H:i:s') . " - DB Connection Error: " . $e->getMessage() . "\n", 3, "admin_errors.log");
        // For debugging, you might want to show a user-friendly message
        // echo "Database connection failed. Please check the error log.";
        return null;
    }
}

// --- Authentication Check (Same as your existing admin files) ---
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (!is_logged_in()) {
    header('Location: index.php'); // Redirect to your admin login page
    exit;
}

// --- Redeem Code Functions ---
/**
 * Creates a new redeem code in the database.
 * @param PDO $conn Database connection object.
 * @param string $code The unique code string.
 * @param int $credits Number of credits the code gives.
 * @param int $max_uses Maximum number of times the code can be used.
 * @param string|null $expires_at Expiry datetime string or null.
 * @return array ['success' => bool, 'message' => string]
 */
function create_redeem_code($conn, $code, $credits, $max_uses, $expires_at) {
    if (empty($code) || $credits <= 0 || $max_uses <= 0) {
        return ['success' => false, 'message' => 'Error: Please fill all fields correctly.'];
    }

    try {
        $stmt_insert = $conn->prepare("
            INSERT INTO redeem_codes (code, credits, max_uses, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt_insert->execute([
            $code,
            $credits,
            $max_uses,
            $expires_at ?: null // Insert NULL if empty
        ]);
        return ['success' => true, 'message' => "Success: Redeem code '$code' created successfully!"];
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry error code
            return ['success' => false, 'message' => "Error: A code with this name '$code' already exists."];
        } else {
            error_log("[ADMIN][ERROR] " . date('Y-m-d H:i:s') . " - Error creating redeem code '$code': " . $e->getMessage() . "\n", 3, "admin_errors.log");
            return ['success' => false, 'message' => 'Error: Failed to create code. Please try again later.'];
        }
    }
}

/**
 * Fetches all existing redeem codes from the database.
 * @param PDO $conn Database connection object.
 * @return array Array of redeem code records.
 */
function get_all_redeem_codes($conn) {
    try {
        $stmt_select = $conn->prepare("SELECT * FROM redeem_codes ORDER BY created_at DESC");
        $stmt_select->execute();
        return $stmt_select->fetchAll();
    } catch (PDOException $e) {
        error_log("[ADMIN][ERROR] " . date('Y-m-d H:i:s') . " - Error fetching redeem codes: " . $e->getMessage() . "\n", 3, "admin_errors.log");
        return [];
    }
}

// --- Handle Form Submission to Create New Code ---
$message = '';
$message_type = ''; // To differentiate between success and error messages

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_code') {
    $code = trim($_POST['code'] ?? '');
    $credits = (int)($_POST['credits'] ?? 0);
    $max_uses = (int)($_POST['max_uses'] ?? 1);
    $expires_at = $_POST['expires_at'] ?? ''; // Expected format: YYYY-MM-DDTHH:MM (from datetime-local input)

    // Basic validation
    if (empty($code)) {
        $message = "Error: Please provide a code name.";
        $message_type = 'error';
    } elseif ($credits <= 0) {
        $message = "Error: Credits must be greater than 0.";
        $message_type = 'error';
    } elseif ($max_uses <= 0) {
        $message = "Error: Max uses must be greater than 0.";
        $message_type = 'error';
    } else {
        // Use the database connection function
        $connection = get_db_connection();
        if ($connection) {
            $result = create_redeem_code($connection, $code, $credits, $max_uses, $expires_at);
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'error';
            
            // If successful, optionally refresh the list of codes
            if ($result['success']) {
                // We will re-fetch codes after the form processing block
            }
        } else {
            $message = "Error: Could not connect to database.";
            $message_type = 'error';
        }
    }
}

// --- Fetch Existing Codes ---
$codes = [];
$connection = get_db_connection();
if ($connection) {
    $codes = get_all_redeem_codes($connection);
} else {
    $message = "Error: Could not connect to database to fetch codes.";
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Redeem Codes - Admin Panel</title>
    <!-- Use the same stylesheet as your other admin pages -->
    <!-- Make sure the path to style.css is correct relative to admin/redeem.php -->
    <link rel="stylesheet" type="text/css" href="style.css"> 
    <style>
        /* You can add specific styles here if needed, or rely on style.css */
        .container {
            max-width: 1200px; /* Adjust as needed */
            margin: 0 auto;
            padding: 20px;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .redeem-form {
            background-color: #f8f9fa;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        .redeem-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .redeem-form input[type="text"],
        .redeem-form input[type="number"],
        .redeem-form input[type="datetime-local"] {
            width: 100%;
            padding: 8px;
            margin-bottom: 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .redeem-form input[type="submit"] {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        .redeem-form input[type="submit"]:hover {
            background-color: #0056b3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
        tr:hover {background-color: #f5f5f5;}
        nav {
            margin-bottom: 20px;
            background-color: #e9ecef;
            padding: 10px;
            border-radius: 5px;
        }
        nav a {
            margin-right: 15px;
            text-decoration: none;
            color: #007bff;
        }
        nav a:hover {
            text-decoration: underline;
        }
        .logout-btn {
             color: #dc3545 !important; /* Red color for logout */
        }
        .logout-btn:hover {
            color: #bd2130 !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Admin Panel - Manage Redeem Codes</h1>
        
        <!-- Navigation bar, same as other admin pages -->
        <nav>
             <!-- Adjust paths according to your admin folder structure -->
            <a href="index.php">Dashboard</a> | 
            <a href="users.php">Users</a> | 
            <a href="config.php">Bot Config</a> | 
            <a href="broadcast.php">Broadcast</a> | 
            <a href="suspicious.php">Suspicious</a> | 
            <a href="redeem.php">Redeem Codes</a> | 
            <a href="index.php?action=logout" class="logout-btn">Logout</a>
        </nav>

        <!-- Display messages -->
        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <h2>Create New Redeem Code</h2>
        <!-- Form to create a new redeem code -->
        <form method="post" class="redeem-form">
            <input type="hidden" name="action" value="create_code">

            <label for="code">Code Name:</label>
            <input type="text" id="code" name="code" placeholder="e.g., WELCOME100" required><br>

            <label for="credits">Credits:</label>
            <input type="number" id="credits" name="credits" min="1" value="10" required><br>

            <label for="max_uses">Max Uses:</label>
            <input type="number" id="max_uses" name="max_uses" min="1" value="100" required><br>

            <label for="expires_at">Expires At (optional):</label>
            <input type="datetime-local" id="expires_at" name="expires_at" title="Leave blank for no expiry"><br>
            <small>Format: YYYY-MM-DD HH:MM (24-hour)</small><br><br>

            <input type="submit" value="Create Code">
        </form>

        <h2>Existing Redeem Codes</h2>
        <!-- Display existing redeem codes in a table -->
        <?php if (empty($codes)): ?>
            <p>No redeem codes found.</p>
        <?php else: ?>
            <table border="1">
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Credits</th>
                    <th>Max Uses</th>
                    <th>Current Uses</th>
                    <th>Expires At</th>
                    <th>Created At</th>
                </tr>
                <?php foreach ($codes as $code_item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($code_item['id']); ?></td>
                    <td><?php echo htmlspecialchars($code_item['code']); ?></td>
                    <td><?php echo htmlspecialchars($code_item['credits']); ?></td>
                    <td><?php echo htmlspecialchars($code_item['max_uses']); ?></td>
                    <td><?php echo htmlspecialchars($code_item['current_uses']); ?></td>
                    <td>
                        <?php
                        if ($code_item['expires_at']) {
                            // Format the datetime for display
                            echo htmlspecialchars(date('Y-m-d H:i', strtotime($code_item['expires_at'])));
                        } else {
                            echo 'Never';
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($code_item['created_at']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>