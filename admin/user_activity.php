<?php
// admin/user_activity.php
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

// Get user ID from URL parameter
$user_id_to_view = $_GET['user_id'] ?? null;

if (!$user_id_to_view) {
    die("Error: No user ID specified.");
}

// --- Fetch User Data ---
function get_user_by_id($conn, $user_id) {
    try {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching user $user_id: " . $e->getMessage());
        return false;
    }
}

// --- Fetch User Activity (Credits History Example) ---
// This is a placeholder. You need to implement how you want to track activity.
// For example, you could have a `user_transactions` table.
function get_user_activity($conn, $user_id) {
    // Example: Get recent credit changes or actions
    // Since there's no transactions table, we'll simulate or show related data
    $activity = [];

    // 1. Check if user was referred
    $stmt_referral = $conn->prepare("SELECT u.user_id as referrer_id, u.username as referrer_username, u.first_name as referrer_first_name FROM users u WHERE u.user_id = (SELECT referred_by FROM users WHERE user_id = ?)");
    $stmt_referral->execute([$user_id]);
    $referrer = $stmt_referral->fetch();
    if ($referrer) {
        $activity[] = [
            'type' => 'Referral',
            'details' => "Referred by User ID: " . $referrer['referrer_id'] .
                         " (Username: " . ($referrer['referrer_username'] ?? 'N/A') .
                         ", Name: " . ($referrer['referrer_first_name'] ?? 'N/A') . ")",
            'timestamp' => 'N/A' // You'd need a timestamp column for this
        ];
    }

    // 2. Check if user referred others
    $stmt_referrals_made = $conn->prepare("SELECT user_id, username, first_name FROM users WHERE referred_by = ?");
    $stmt_referrals_made->execute([$user_id]);
    $referees = $stmt_referrals_made->fetchAll();
    foreach($referees as $referee) {
        $activity[] = [
            'type' => 'Referral Bonus Given',
            'details' => "Gave bonus for referring User ID: " . $referee['user_id'] .
                         " (Username: " . ($referee['username'] ?? 'N/A') .
                         ", Name: " . ($referee['first_name'] ?? 'N/A') . ")",
            'timestamp' => 'N/A'
        ];
    }

    // 3. Check redeem code usage (assuming you have the tables from previous discussions)
    // Make sure `used_redeem_codes` and `redeem_codes` tables exist
    $stmt_redeems = $conn->prepare("
        SELECT urc.used_at, rc.code, rc.credits
        FROM used_redeem_codes urc
        JOIN redeem_codes rc ON urc.code_id = rc.id
        WHERE urc.user_id = ?
        ORDER BY urc.used_at DESC
    ");
    $stmt_redeems->execute([$user_id]);
    $redeems = $stmt_redeems->fetchAll();
    foreach($redeems as $redeem) {
        $activity[] = [
            'type' => 'Redeem Code Used',
            'details' => "Used code '{$redeem['code']}' and gained {$redeem['credits']} credits.",
            'timestamp' => $redeem['used_at']
        ];
    }

    // 4. Add more activity types here as needed (e.g., searches performed if logged)

    return $activity;
}

$conn = get_db_connection();
$user_data = get_user_by_id($conn, $user_id_to_view);
$activities = get_user_activity($conn, $user_id_to_view);

if (!$user_data) {
    die("Error: User with ID $user_id_to_view not found.");
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>User Activity - <?php echo htmlspecialchars($user_id_to_view); ?></title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .activity-table { margin-top: 20px; width: 100%; border-collapse: collapse; }
        .activity-table th, .activity-table td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .activity-table th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="container">
        <h1>User Activity for <?php echo htmlspecialchars($user_data['user_id']); ?></h1>
        <nav>
            <a href="index.php">Dashboard</a> |
            <a href="users.php">Users</a> |
            <a href="config.php">Bot Config</a> |
            <a href="broadcast.php">Broadcast</a> |
            <a href="suspicious.php">Suspicious</a> |
            <a href="redeem.php">Redeem Codes</a> |
            <a href="index.php?action=logout" class="logout-btn">Logout</a>
        </nav>

        <h2>User Details</h2>
        <ul>
            <li><strong>User ID:</strong> <?php echo htmlspecialchars($user_data['user_id']); ?></li>
            <li><strong>Username:</strong> <?php echo htmlspecialchars($user_data['username'] ?? 'N/A'); ?></li>
            <li><strong>First Name:</strong> <?php echo htmlspecialchars($user_data['first_name'] ?? 'N/A'); ?></li>
            <li><strong>Credits:</strong> <?php echo htmlspecialchars($user_data['credits']); ?></li>
            <li><strong>Referred By:</strong> <?php echo htmlspecialchars($user_data['referred_by'] ?? 'N/A'); ?></li>
            <li><strong>First Time:</strong> <?php echo $user_data['first_time'] ? 'Yes' : 'No'; ?></li>
            <li><strong>Joined Channel:</strong> <?php echo $user_data['joined_channel'] ? 'Yes' : 'No'; ?></li>
            <li><strong>Created At:</strong> <?php echo htmlspecialchars($user_data['created_at']); ?></li>
            <li><strong>Updated At:</strong> <?php echo htmlspecialchars($user_data['updated_at']); ?></li>
        </ul>

        <h2>Activity Log</h2>
        <?php if (empty($activities)): ?>
            <p>No specific activities found for this user.</p>
        <?php else: ?>
            <table class="activity-table" border="1">
                <tr>
                    <th>Type</th>
                    <th>Details</th>
                    <th>Timestamp</th>
                </tr>
                <?php foreach ($activities as $act): ?>
                <tr>
                    <td><?php echo htmlspecialchars($act['type']); ?></td>
                    <td><?php echo htmlspecialchars($act['details']); ?></td>
                    <td><?php echo htmlspecialchars($act['timestamp']); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <br>
        <a href="users.php">Back to User List</a>
    </div>
</body>
</html>