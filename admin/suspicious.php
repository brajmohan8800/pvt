<?php
// admin/suspicious.php
session_start();

// --- Database Connection (Same as other admin files) ---
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

// --- Authentication Check (Same as other admin files) ---
function is_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

if (!is_logged_in()) {
    header('Location: index.php');
    exit;
}

// Get suspicious activities
$conn = get_db_connection();
$stmt = $conn->query("SELECT * FROM suspicious_activities ORDER BY last_seen DESC");
$activities = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Suspicious Activities</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Suspicious Activities</h1>
        <nav>
            <a href="index.php">Dashboard</a> |
            <a href="users.php">Users</a> |
            <a href="config.php">Bot Config</a> |
            <a href="broadcast.php">Broadcast</a> |
            <a href="suspicious.php">Suspicious</a> | <!-- NEW LINK -->
            <a href="index.php?action=logout" class="logout-btn">Logout</a>
        </nav>

        <?php if (empty($activities)): ?>
            <p>No suspicious activities found.</p>
        <?php else: ?>
            <table border="1">
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Identifier</th>
                    <th>User IDs</th>
                    <th>First Seen</th>
                    <th>Last Seen</th>
                </tr>
                <?php foreach ($activities as $activity): ?>
                <tr>
                    <td><?php echo htmlspecialchars($activity['id']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($activity['type'])); ?></td>
                    <td><?php echo htmlspecialchars($activity['identifier']); ?></td>
                    <td>
                        <?php
                        $user_ids = explode(',', $activity['user_ids']);
                        foreach($user_ids as $uid) {
                            echo '<a href="users.php?search=' . urlencode($uid) . '" target="_blank">' . htmlspecialchars($uid) . '</a> ';
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($activity['first_seen']); ?></td>
                    <td><?php echo htmlspecialchars($activity['last_seen']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>