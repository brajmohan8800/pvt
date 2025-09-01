<?php
// admin/broadcast.php
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

// --- Broadcast Function (Simplified from bot.php) ---
function send_telegram_message_simple($chat_id, $text, $bot_token) {
    $apiUrl = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $params = [
        'chat_id' => $chat_id,
        'text' => $text
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout for individual requests
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200) {
        return true;
    } else {
        error_log("Error sending message to chat $chat_id. HTTP $httpCode. Response: $response");
        return false;
    }
}

function send_broadcast_message($conn, $message_text, $bot_token) {
    // Get all users (or a subset)
    $stmt = $conn->query("SELECT DISTINCT user_id FROM users WHERE user_id IS NOT NULL");
    $users = $stmt->fetchAll();

    $success_count = 0;
    $error_count = 0;

    foreach ($users as $user) {
        $chat_id = $user['user_id'];
        if (send_telegram_message_simple($chat_id, $message_text, $bot_token)) {
            $success_count++;
        } else {
            $error_count++;
        }
        // Small delay to respect rate limits
        usleep(100000); // 0.1 seconds
    }

    return ['sent' => $success_count, 'failed' => $error_count];
}

// Handle broadcast
$message = '';
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $broadcast_text = $_POST['message'] ?? '';
    // Get bot token for sending
    $conn = get_db_connection();
    $config_stmt = $conn->query("SELECT bot_token FROM bot_config WHERE id = 1 LIMIT 1");
    $config = $config_stmt->fetch();
    $bot_token = $config['bot_token'] ?? '';

    if ($broadcast_text && $bot_token) {
        $result = send_broadcast_message($conn, $broadcast_text, $bot_token);
        if ($result) {
            $message = "Broadcast sent! Success: {$result['sent']}, Failed: {$result['failed']}.";
        } else {
            $message = "Error sending broadcast message.";
        }
    } else {
        $message = "Message text and bot configuration are required.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Broadcast Message</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Broadcast Message</h1>
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
            <label for="message">Message:</label><br>
            <textarea id="message" name="message" rows="5" cols="50" placeholder="Enter your broadcast message here..." required></textarea><br><br>
            <input type="submit" value="Send Broadcast">
        </form>

        <?php if ($result): ?>
        <p><strong>Result:</strong> Sent to <?php echo $result['sent']; ?> users, <?php echo $result['failed']; ?> failed.</p>
        <?php endif; ?>
    </div>
</body>
</html>