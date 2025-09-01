<?php
// admin/config.php
session_start();

// --- Database Connection (Same as index.php) ---
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

// --- Authentication Check ---
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (!is_logged_in()) {
    header('Location: index.php');
    exit;
}

// --- Config Functions ---
function get_bot_config($conn) {
    $stmt = $conn->query("SELECT * FROM bot_config WHERE id = 1");
    return $stmt->fetch();
}

function update_bot_config($conn, $bot_token, $api_token) {
    try {
        $stmt = $conn->prepare("UPDATE bot_config SET bot_token = ?, api_global_token = ? WHERE id = 1");
        return $stmt->execute([$bot_token, $api_token]);
    } catch (PDOException $e) {
        error_log("Error updating bot config: " . $e->getMessage());
        return false;
    }
}

// Handle config update
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bot_token = $_POST['bot_token'] ?? '';
    $api_token = $_POST['api_token'] ?? '';

    if ($bot_token && $api_token) {
        $conn = get_db_connection();
        if (update_bot_config($conn, $bot_token, $api_token)) {
            $message = "Configuration updated successfully.";
        } else {
            $message = "Error updating configuration.";
        }
    } else {
        $message = "Both tokens are required.";
    }
}

// Get current config
$conn = get_db_connection();
$config = get_bot_config($conn);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Bot Configuration</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Bot Configuration</h1>
      <nav>
    <a href="index.php">Dashboard</a> |
    <a href="users.php">Users</a> |
    <a href="config.php">Bot Config</a> |
    <a href="broadcast.php">Broadcast</a> |
    <a href="suspicious.php">Suspicious</a>
    <a href="redeem.php">Redeem Codes</a> | <!-- ADD THIS LINE -->
    <a href="index.php?action=logout" class="logout-btn">Logout</a>
</nav>

        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form method="post">
            <label for="bot_token">Bot Token:</label><br>
            <input type="text" id="bot_token" name="bot_token" value="<?php echo htmlspecialchars($config['bot_token'] ?? ''); ?>" size="50" required><br><br>

            <label for="api_token">API Global Token:</label><br>
            <input type="text" id="api_token" name="api_token" value="<?php echo htmlspecialchars($config['api_global_token'] ?? ''); ?>" size="50" required><br><br>

            <!-- Add other config fields here if needed -->

            <input type="submit" value="Update Configuration">
        </form>
    </div>
</body>
</html>