<?php
// admin/index.php
session_start();

// --- Database Connection ---
$DB_CONFIG = [
    'host' => 'localhost',
    'dbname' => 'osintx',
    'user' => 'root',
    'password' => ''
];

function get_db_connection() {
    global $DB_CONFIG;
    try {
        $dsn = "mysql:host={$DB_CONFIG['host']};dbname={$DB_CONFIG['dbname']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);
    } catch (PDOException $e) {
        error_log("DB Connection Error: " . $e->getMessage());
        die("Database connection failed.");
    }
}

// --- Authentication Logic ---
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// WARNING: This is a very insecure way to handle login.
// For production, use a proper user table with hashed passwords.
function login($username, $password) {
    // Hardcoded credentials for demonstration only!
    $hardcoded_username = 'Brajmohan88';
    $hardcoded_password = 'Brajmo8th'; // CHANGE THIS!

    if ($username === $hardcoded_username && $password === $hardcoded_password) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        return true;
    }
    return false;
}

function logout() {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle login form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (login($username, $password)) {
        // Redirect to dashboard (which is this page when logged in)
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
}

// If logged in, show dashboard
if (is_logged_in()) {
    // Fetch dashboard data
    $conn = get_db_connection();
    $total_users_stmt = $conn->query("SELECT COUNT(*) as total FROM users");
    $total_users = $total_users_stmt->fetch()['total'];

    $live_users_stmt = $conn->query("SELECT COUNT(*) as live FROM users WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $live_users = $live_users_stmt->fetch()['live'];

    $config_stmt = $conn->query("SELECT * FROM bot_config WHERE id = 1");
    $config = $config_stmt->fetch();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Admin Dashboard</h1>
     <nav>
    <a href="index.php">Dashboard</a> |
    <a href="users.php">Users</a> |
    <a href="config.php">Bot Config</a> |
    <a href="broadcast.php">Broadcast</a> |
    <a href="suspicious.php">Suspicious</a>
    <a href="redeem.php">Redeem Codes</a> | <!-- ADD THIS LINE -->
    <a href="index.php?action=logout" class="logout-btn">Logout</a>
</nav>

        <div class="dashboard-stats">
            <div class="stat-box">
                <h3>Total Users</h3>
                <p><?php echo $total_users; ?></p>
            </div>
            <div class="stat-box">
                <h3>Live Users (Est.)</h3>
                <p><?php echo $live_users; ?></p>
            </div>
            <div class="stat-box">
                <h3>Bot Token</h3>
                <p><?php echo htmlspecialchars(substr($config['bot_token'] ?? 'N/A', 0, 10)) . '...'; ?></p>
            </div>
             <div class="stat-box">
                <h3>API Token</h3>
                <p><?php echo htmlspecialchars(substr($config['api_global_token'] ?? 'N/A', 0, 10)) . '...'; ?></p>
            </div>
        </div>
    </div>
</body>
</html>
<?php
    exit; // Stop script execution after showing dashboard
}
// If not logged in, show login form
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="login-container">
        <h2>Admin Login</h2>
        <?php if ($error): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required><br><br>
            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required><br><br>
            <input type="submit" value="Login">
        </form>
        <!-- Security Note -->
        <p style="font-size: 0.8em; color: #666; margin-top: 20px;">
            <strong>Security Note:</strong>  <code>welcome</code> / <code>admin</code>. pro 
        </p>
    </div>
</body>
</html>