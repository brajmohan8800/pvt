<?php
// admin/users.php
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

// --- User Functions ---
function get_all_users($conn, $search = '') {
    if ($search) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id LIKE ? OR username LIKE ? OR first_name LIKE ?");
        $searchTerm = "%$search%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    } else {
        $stmt = $conn->query("SELECT * FROM users ORDER BY id DESC");
    }
    return $stmt->fetchAll();
}

function update_user_credits($conn, $user_id, $amount) {
    try {
        $stmt = $conn->prepare("UPDATE users SET credits = credits + ? WHERE user_id = ?");
        return $stmt->execute([$amount, $user_id]);
    } catch (PDOException $e) {
        error_log("Error updating credits for user $user_id: " . $e->getMessage());
        return false;
    }
}

// Handle credit update
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_credits') {
    $user_id = $_POST['user_id'] ?? '';
    $credits = (int)($_POST['credits'] ?? 0);
    if ($user_id && $credits != 0) {
        $conn = get_db_connection();
        if (update_user_credits($conn, $user_id, $credits)) {
            $message = "Credits updated successfully for user $user_id.";
        } else {
            $message = "Error updating credits.";
        }
    } else {
         $message = "Invalid user ID or credit amount.";
    }
}

// Get users
$conn = get_db_connection();
$search = $_GET['search'] ?? '';
$users = get_all_users($conn, $search);
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Management</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        /* Ensure consistent table cell padding and alignment */
        table td, table th {
            padding: 8px 12px; /* Adjust padding as needed */
            vertical-align: top; /* Align content to the top */
        }
        /* Style for the "See Activity" link/button */
        .activity-link {
            display: inline-block;
            padding: 4px 8px;
            background-color: #28a745; /* Bootstrap success green */
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9em; /* Slightly smaller font */
            margin-left: 5px; /* Space between Update button and See Activity link */
        }
        .activity-link:hover {
            background-color: #218838; /* Darker green on hover */
        }
        /* Ensure the form containing the Update button and See Activity link doesn't wrap */
        .action-cell {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>User Management</h1>
     <nav>
    <a href="index.php">Dashboard</a> |
    <a href="users.php">Users</a> |
    <a href="config.php">Bot Config</a> |
    <a href="broadcast.php">Broadcast</a> |
    <a href="suspicious.php">Suspicious</a> |
    <a href="redeem.php">Redeem Codes</a> | <!-- Ensure this link is present -->
    <a href="index.php?action=logout" class="logout-btn">Logout</a>
</nav>

        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form method="get" class="search-form">
            <input type="text" name="search" placeholder="Search by ID, Username, Name" value="<?php echo htmlspecialchars($search); ?>">
            <input type="submit" value="Search">
        </form>

        <table border="1">
            <tr>
                <th>ID</th>
                <th>User ID</th>
                <th>Username</th>
                <th>First Name</th>
                <th>Credits</th>
                <th>Referred By</th>
                <th>First Time</th>
                <th>Joined Channel</th>
                <th>Created At</th>
                <th>Updated At</th>
                <th>Action</th> <!-- Updated header -->
            </tr>
            <?php foreach ($users as $user): ?>
            <tr>
                <td><?php echo htmlspecialchars($user['id']); ?></td>
                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                <td><?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($user['first_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($user['credits']); ?></td>
                <td><?php echo htmlspecialchars($user['referred_by'] ?? 'N/A'); ?></td>
                <td><?php echo $user['first_time'] ? 'Yes' : 'No'; ?></td>
                <td><?php echo $user['joined_channel'] ? 'Yes' : 'No'; ?></td>
                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                <td><?php echo htmlspecialchars($user['updated_at']); ?></td>
                <td class="action-cell"> <!-- Apply the class for styling -->
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['user_id']); ?>">
                        <input type="hidden" name="action" value="add_credits">
                        <input type="number" name="credits" value="0" min="-1000" max="1000" style="width: 60px;" title="Positive to add, negative to deduct">
                        <input type="submit" value="Update">
                    </form>
                    <!-- NEW: See Activity Link -->
                    <a href="user_activity.php?user_id=<?php echo urlencode($user['user_id']); ?>" class="activity-link" target="_blank" title="View user's activity log">See Activity</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</body>
</html>