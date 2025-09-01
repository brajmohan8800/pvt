<?php
// --- Configuration ---
// Using simple echo for minimal logging or remove entirely
function log_info($msg) {
    // echo "[INFO] $msg\n"; // Uncomment to enable minimal info prints
    // For debugging, you might want to log to a file:
    error_log("[INFO] " . date('Y-m-d H:i:s') . " - $msg\n", 3, "C:/xampp/htdocs/dur/bot_debug.log");
}

function log_error($msg) {
    // echo "[ERROR] $msg\n"; // Uncomment to enable minimal error prints
    // For debugging, you might want to log to a file:
    error_log("[ERROR] " . date('Y-m-d H:i:s') . " - $msg\n", 3, "C:/xampp/htdocs/dur/bot_errors.log");
}

// --- Compatibility Function ---
// For older PHP versions that don't have str_starts_with
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

// --- Database Connection Function ---
// Replace with your database credentials
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
        $pdo = new PDO($dsn, $DB_CONFIG['user'], $DB_CONFIG['password'], $options);
        // log_info("Connected to MySQL database");
        return $pdo;
    } catch (PDOException $e) {
        log_error("Error connecting to MySQL: " . $e->getMessage());
        return null;
    }
}

// --- Load Global Config from DB ---
function load_global_config() {
    $connection = get_db_connection();
    if (!$connection) {
        log_error("Failed to get database connection for loading config.");
        return null;
    }
    try {
        $stmt = $connection->prepare("SELECT * FROM bot_config WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $config = $stmt->fetch();
        return $config;
    } catch (PDOException $e) {
        log_error("Error loading global config: " . $e->getMessage());
        return null;
    }
}

// Load config at startup
$global_config = load_global_config();
if (!$global_config) {
    echo "Critical: Could not load global configuration. Exiting.\n";
    exit(1);
}

// Configuration (loaded from DB)
// IMPORTANT: Fixed URL spacing. Make sure your DB URL is correct without trailing spaces.
$url = rtrim($global_config['api_base_url'], '/') . "/"; // Ensure URL ends with a slash
$bot_token = $global_config['bot_token'];
$api_token = $global_config['api_global_token']; // Global token for your mock API
$lang = "en";
$limit = 300;

// --- REPLACE 'your_bot_username' and 'your_channel_username' BELOW with values from DB if needed, or use DB values ---
$BOT_USERNAME = ltrim($global_config['bot_username'], '@'); // Remove @ if present in DB
$REQUIRED_CHANNEL = $global_config['required_channel']; // e.g., "@primescripter"

// --- Path to your welcome image ---
$WELCOME_IMAGE_PATH = "welcome_image.jpg"; // Make sure this file exists in the same directory

// --- User Data Functions (Using Database) ---
function get_user($user_id) {
    $connection = get_db_connection();
    if (!$connection) {
        return null;
    }
    try {
        $stmt = $connection->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if ($user) {
            // Convert relevant fields if needed (MySQL returns string for INT sometimes)
            $user['credits'] = (int)$user['credits'];
            $user['first_time'] = (bool)$user['first_time'];
            $user['joined_channel'] = (bool)$user['joined_channel'];
            if ($user['referred_by'] !== null) {
                $user['referred_by'] = (int)$user['referred_by'];
            }
            return $user;
        } else {
            // User not found, return default structure
            return [
                "user_id" => $user_id,
                "credits" => 0,
                "referred_by" => null,
                "first_time" => true,
                "joined_channel" => false,
                "username" => null,
                "first_name" => null
            ];
        }
    } catch (PDOException $e) {
        log_error("Error getting user $user_id: " . $e->getMessage());
        return null;
    }
}

// *************************************************************************
// * FIXED: Moved check_membership outside to make it a global function   *
// *************************************************************************
function check_membership($user_id) {
    global $REQUIRED_CHANNEL, $bot_token; // Use $bot_token directly
    /** Checks if a user is a member of the required channel. */
    try {
        // Use the global REQUIRED_CHANNEL variable
        // Example using cURL to call getChatMember
        $apiUrl = "https://api.telegram.org/bot" . $bot_token . "/getChatMember";
        $params = [
            'chat_id' => $REQUIRED_CHANNEL,
            'user_id' => $user_id
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            $result = json_decode($response, true);
            if (isset($result['ok']) && $result['ok'] && isset($result['result']['status'])) {
                $status = $result['result']['status'];
                // status can be 'creator', 'administrator', 'member', 'restricted', 'left', 'kicked'
                return in_array($status, ['creator', 'administrator', 'member', 'restricted']);
            }
        }
        // If not OK or status not found, assume not a member
        log_error("API Error checking membership for user $user_id: HTTP $httpCode, Response: $response");
    } catch (Exception $e) {
        log_error("Unexpected error checking membership for user $user_id: " . $e->getMessage());
    }
    return false; // Assume not a member if check fails
}

function create_or_update_user($user_id, $username = null, $first_name = null, $referred_by = null) {
    $connection = get_db_connection();
    if (!$connection) {
        return false;
    }
    try {
        // Check if user exists
        $stmt_check = $connection->prepare("SELECT user_id FROM users WHERE user_id = ?");
        $stmt_check->execute([$user_id]);
        $exists = $stmt_check->fetch();

        if ($exists) {
            // Update existing user (e.g., username might change)
            $stmt_update = $connection->prepare("
                UPDATE users
                SET username = ?, first_name = ?
                WHERE user_id = ?
            ");
            $stmt_update->execute([$username, $first_name, $user_id]);
        } else {
            // Insert new user with defaults
            $stmt_insert = $connection->prepare("
                INSERT INTO users (user_id, username, first_name, credits, referred_by, first_time, joined_channel)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert->execute([$user_id, $username, $first_name, 0, $referred_by, true, false]);
        }
        return true;
    } catch (PDOException $e) {
        log_error("Error creating/updating user $user_id: " . $e->getMessage());
        return false;
    }
}

function add_credits($user_id, $amount, $referrer_id = null) {
    global $bot_token; // Use $bot_token directly
    $connection = get_db_connection();
    if (!$connection) {
        return false;
    }
    try {
        // Start transaction
        $connection->beginTransaction();

        // Add credits to the user
        $stmt_update_credits = $connection->prepare("UPDATE users SET credits = credits + ? WHERE user_id = ?");
        $stmt_update_credits->execute([$amount, $user_id]);

        // Handle referral bonus
        if ($referrer_id && $user_id != $referrer_id) {
            // Check if the user is new (first_time flag)
            $stmt_get_first_time = $connection->prepare("SELECT first_time FROM users WHERE user_id = ?");
            $stmt_get_first_time->execute([$user_id]);
            $user_first_time_row = $stmt_get_first_time->fetch();
            $user_first_time = $user_first_time_row ? (bool)$user_first_time_row['first_time'] : false;

            if ($user_first_time) { // If first_time is True
                $stmt_update_referrer = $connection->prepare("UPDATE users SET credits = credits + ? WHERE user_id = ?");
                $stmt_update_referrer->execute([2, $referrer_id]);
                try {
                    // Notify referrer (handle potential errors if user blocks bot)
                    // Example using cURL to send a message
                    $apiUrl = "https://api.telegram.org/bot" . $bot_token . "/sendMessage";
                    $params = [
                        'chat_id' => $referrer_id,
                        'text' => "🎉 You earned 2 credits! A referred user ($user_id) performed an action."
                    ];
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $apiUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    curl_close($ch);
                    // You might want to check the response for errors here
                } catch (Exception $e) {
                    log_error("Could not notify referrer $referrer_id: " . $e->getMessage());
                }
                // Mark the new user as not first time after giving bonus
                $stmt_update_first_time = $connection->prepare("UPDATE users SET first_time = FALSE WHERE user_id = ?");
                $stmt_update_first_time->execute([$user_id]);
            }
        }

        // Commit transaction
        $connection->commit();
        return true;
    } catch (PDOException $e) {
        log_error("Error adding credits for user $user_id: " . $e->getMessage());
        // Rollback transaction on error
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        return false;
    }
}

function mark_user_joined($user_id) {
    $connection = get_db_connection();
    if (!$connection) {
        return false;
    }
    try {
        $stmt = $connection->prepare("UPDATE users SET joined_channel = TRUE WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        log_error("Error marking user $user_id as joined: " . $e->getMessage());
        return false;
    }
}

function gen_ref_link($user_id) {
    global $BOT_USERNAME;
    // Fixed URL spacing
    return "https://t.me/$BOT_USERNAME?start=ref$user_id";
}

// --- Report Generation (with minimal logging) ---
// $cash_reports = []; // <-- Removed global storage
function generate_report($query, $query_id, $user_id_context) {
    // global $cash_reports, $url, $api_token, $limit, $lang; // <-- Removed $cash_reports from global
    global $url, $api_token, $limit, $lang; // <-- Keep only necessary globals
    // Ensure request is clean
    $request_query = trim(explode("\n", $query)[0]);
    $data = json_encode(["token" => $api_token, "request" => $request_query, "limit" => $limit, "lang" => $lang]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout

    try {
        // log_info("Making API request to $url for query ID: $query_id"); // Minimal log
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // log_info("API request for ID $query_id completed. Status code: $httpCode"); // Minimal log

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            log_error("cURL Error for ID $query_id: $error_msg");
            return null;
        }
        curl_close($ch);

        if ($httpCode != 200) {
            log_error("HTTP Error for ID $query_id: HTTP $httpCode");
            return null;
        }

        $response_json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_error("JSON Error for ID $query_id: " . json_last_error_msg());
            // log_error("Response content snippet: " . substr($response, 0, 100) . "..."); // Log only first 100 chars
            return null;
        }

        // IMPORTANT: Removed full response logging to save memory/logs
        // log_info("API Response keys for ID $query_id: " . print_r(array_keys($response_json), true)); // Log only keys

        if (isset($response_json["Error code"])) {
            log_error("API Error for ID $query_id: " . $response_json['Error code']);
            return null;
        }

        if (!isset($response_json["List"])) {
             log_error("API Response for ID $query_id missing 'List' key");
             return null;
        }

        // Initialize the report array for this specific query
        $report = []; // <-- Local variable, not global $cash_reports
        foreach ($response_json["List"] as $database_name => $db_info) {
            if ($database_name == "No results found") {
                 $report[] = "<b>No data found for this query.</b>"; // <-- Add directly to $report
                 continue;
            }

            if (isset($db_info["Data"]) && !empty($db_info["Data"])) {
                foreach ($db_info["Data"] as $data_entry) {
                     $text_parts = ["<b>🔍 Source: $database_name</b>", ""];
                     foreach ($data_entry as $key => $value) {
                          $text_parts[] = "<b>$key:</b> $value";
                     }
                     $full_text = implode("\n", $text_parts);
                     if (strlen($full_text) > 3500) {
                         $full_text = substr($full_text, 0, 3500) . "\n\n⚠️ Data truncated.";
                     }
                     $report[] = $full_text; // <-- Add directly to $report
                }
            } else {
                $report[] = "<b>🔍 Source: $database_name</b>\n\nNo detailed data available."; // <-- Add directly to $report
            }
        }

        // --- NEW: Store results in database ---
        if (!empty($report)) {
            $connection = get_db_connection();
            if ($connection) {
                try {
                    // Serialize the report array to store it as text
                    $serialized_report = json_encode($report);

                    $stmt_insert = $connection->prepare("
                        INSERT INTO search_results (query_id, user_id, report_data)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE report_data = VALUES(report_data), created_at = CURRENT_TIMESTAMP
                    ");
                    $stmt_insert->execute([strval($query_id), $user_id_context, $serialized_report]);
                    log_info("Search results for ID $query_id stored in DB for user $user_id_context.");
                } catch (PDOException $e) {
                    log_error("Error storing search results for ID $query_id in DB: " . $e->getMessage());
                    // You might choose to continue even if storing fails, or handle it differently
                }
            } else {
                 log_error("Failed to get DB connection to store search results for ID $query_id.");
            }
        }
        // --- END NEW ---

        // Log only the number of sources
        // log_info("Report generated for ID $query_id. Sources found: " . count($report));
        // Do NOT store in $cash_reports
        // return $cash_reports[strval($query_id)] if $cash_reports[strval($query_id)] else None // <-- Old Python-like logic
        // Instead, return the local $report array directly
        return !empty($report) ? $report : null; // <-- Return the local array

    } catch (Exception $e) {
        log_error("Unexpected error in generate_report for ID $query_id: " . $e->getMessage());
        return null;
    }
}

function create_inline_keyboard($query_id, $page_id, $count_page) {
    // This function would need to return a structure representing the keyboard
    // suitable for the Telegram Bot API response.
    // For now, we'll return a simple associative array structure.
    // A real implementation would need to format this correctly for the Telegram API.
    $markup = ['inline_keyboard' => []];

    if ($page_id < 0) {
        $page_id = $count_page - 1;
    } elseif ($page_id >= $count_page) {
        $page_id = 0;
    }

    if ($count_page <= 1) {
        return $markup;
    }

    $markup['inline_keyboard'][] = [
        ['text' => "<<", 'callback_data' => "/page $query_id " . ($page_id - 1)],
        ['text' => ($page_id + 1) . "/" . $count_page, 'callback_data' => "noop"],
        ['text' => ">>", 'callback_data' => "/page $query_id " . ($page_id + 1)]
    ];

    return $markup;
}

function main_menu() {
    // Returns a structure for the main menu inline keyboard
    return [
        'inline_keyboard' => [
            [
                ['text' => "🔍 Check Data Breach (Phone)", 'callback_data' => "search_number"],
                ['text' => "🔎 Search Username", 'callback_data' => "search_username"]
            ],
            [
                ['text' => "👥 Refer & Earn", 'callback_data' => "refer"],
                ['text' => "💳 Buy Credits", 'callback_data' => "buy_credits"]
            ],
            [
                ['text' => "💰 Price List", 'callback_data' => "price_list"],
                ['text' => "📊 Balance", 'callback_data' => "balance"]
            ],
            // --- NEW: Redeem Code Menu Item ---
            [
                ['text' => "🎟️ Redeem Code", 'callback_data' => "redeem_code"]
            ]
            // --- END NEW ---
        ]
    ];
}

// --- Bot Initialization ---
// Store token for use in functions
// Removed the polling loop and startup message from here


// --- Webhook Handler (NEW) ---
// Get the raw POST data from Telegram
$input = file_get_contents('php://input');

// --- CHANGED: Enhanced JSON decoding and error handling ---
// Check if input is empty
if (empty($input)) {
    log_error("Received empty request body.");
    http_response_code(400); // Bad Request
    echo "Error: Empty request";
    exit;
}

// Attempt to decode JSON
$update = json_decode($input, true);

// Check if the update was decoded successfully
if (is_array($update)) {
    // Log the incoming update (optional, for debugging)
    // file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " - Update received: " . print_r($update, true) . "\n", FILE_APPEND);

    // Process the update (message, callback_query, etc.)
    process_update_webhook($update); // Call a function to handle the update
} else {
    // Log error if JSON decoding failed, including the raw input for debugging
    $jsonError = json_last_error_msg(); // Get specific JSON error message
    log_error("Failed to decode JSON update. Error: $jsonError. Raw input: " . substr($input, 0, 200) . (strlen($input) > 200 ? '...' : '')); // Log only first 200 chars to prevent huge logs
    http_response_code(400); // Bad Request
    echo "Error: Invalid JSON";
    exit;
}
// --- END CHANGED ---


// --- Function to process updates from webhook (NEW) ---
function process_update_webhook($update) {
    global $bot_token;

    // Check if it's a message
    if (isset($update['message'])) {
        $message = $update['message'];
        // Check if it's a /start command
        if (isset($message['text']) && str_starts_with($message['text'], '/start')) {
            send_welcome($message); // Call your existing welcome function
        } else if (isset($message['text'])) {
            // Handle user input after prompts (e.g., phone number, username)
            // This is where handle_user_input is called
            handle_user_input($message);
        }
        // Add logic for other message types if needed (e.g., photos, documents)
    }
    // Check if it's a callback query (button presses)
    elseif (isset($update['callback_query'])) {
        callback_query($update['callback_query']); // Call your existing callback function
    }
    // Add logic for other update types if needed (e.g., inline queries)

    // Acknowledge receipt to Telegram by sending a 200 OK response
    http_response_code(200);
    echo "OK"; // Sending a simple response is good practice
}


// --- Handler Functions (Keep these as they are, they are called by process_update_webhook) ---
// These would need to be fully implemented using the Telegram Bot API.

function send_welcome($message) {
    global $REQUIRED_CHANNEL, $WELCOME_IMAGE_PATH, $BOT_USERNAME, $bot_token;
    $user_id = $message['from']['id'];
    $username = $message['from']['username'] ?? null;
    $first_name = $message['from']['first_name'] ?? null;
    $referrer_id = null;

    // Ensure user exists in DB
    create_or_update_user($user_id, $username, $first_name);

    // Check for referral parameter
    if (isset($message['text']) && count(explode(' ', $message['text'])) > 1) {
        $parts = explode(' ', $message['text']);
        $ref_code = $parts[1];
        if (str_starts_with($ref_code, "ref") && ctype_digit(substr($ref_code, 3))) {
            try {
                $referrer_id = (int)substr($ref_code, 3);
                if ($referrer_id != $user_id) {
                    $user_data = get_user($user_id);
                    if ($user_data && $user_data["referred_by"] === null) {
                        // Update DB with referrer
                        $connection = get_db_connection();
                        if ($connection) {
                            try {
                                $stmt = $connection->prepare("UPDATE users SET referred_by = ? WHERE user_id = ?");
                                $stmt->execute([$referrer_id, $user_id]);
                                // FIXED: Removed log_info call, added inline logging or use error_log if needed
                                // log_info("User $user_id referred by $referrer_id");
                                error_log("[INFO] " . date('Y-m-d H:i:s') . " - User $user_id referred by $referrer_id\n", 3, "bot_debug.log");
                                // Give referral bonus immediately upon using link
                                add_credits($referrer_id, 2, null);
                            } catch (PDOException $e) {
                                log_error("Error updating referrer for $user_id: " . $e->getMessage());
                                if ($connection->inTransaction()) {
                                    $connection->rollBack();
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                log_error("Error parsing referrer ID: " . $e->getMessage());
            }
        }
    }

    $user = get_user($user_id);
    if (!$user) {
        // Send message using cURL
        send_telegram_message($message['chat']['id'], "❌ An error occurred. Please try again later.", null);
        return;
    }

    // --- CHANNEL VERIFICATION ---
    // Check if user has actually joined the channel using the global function
    $is_member = check_membership($user_id);

    // If not joined, prompt to join
    if (!$is_member) {
         $welcome_text = (
             "👋 *Welcome to the osintricx Data Search Bot!*\n\n"
             . "🔍 Search for phone numbers or usernames.\n"
             . "🚫 *No real personal information is searched or displayed.*\n\n"
             . "📢 *Please join our channel first to use the bot:*"
         );
         $markup = [
            'inline_keyboard' => [
                [['text' => "📢 Join Channel", 'url' => 'https://t.me/' . ltrim($REQUIRED_CHANNEL, '@')]],
                [['text' => "✅ I've Joined, Continue", 'callback_data' => "joined_channel"]]
            ]
         ];
         // Send image with caption or text only
         // Sending photos requires a more complex cURL setup with multipart/form-data
         // For simplicity, we'll send text only here.
         // if (file_exists($WELCOME_IMAGE_PATH)) {
         //     // Implement photo sending logic here
         //     // ...
         // } else {
             // log_error("Welcome image not found at $WELCOME_IMAGE_PATH. Sending text only.");
             send_telegram_message($message['chat']['id'], $welcome_text, $markup, "Markdown");
         // }
         return; // Don't show main menu or give credits yet
    }

    // If user has joined, proceed normally
    // Ensure user is marked as joined in DB if check passed
    if (!$user["joined_channel"]) {
        mark_user_joined($user_id);
    }

    if ($user["first_time"]) {
        $success = add_credits($user_id, 4, $referrer_id);
        if ($success) {
            // Update first_time status after giving credits
            $connection = get_db_connection();
            if ($connection) {
                try {
                    $stmt = $connection->prepare("UPDATE users SET first_time = FALSE WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                } catch (PDOException $e) {
                    log_error("Error updating first_time for $user_id: " . $e->getMessage());
                }
            }
        }
    }

    $welcome_text = (
        "👋 *Welcome back to the osintricx Data Search Bot!*\n"
        . "🔍 Use the buttons below to explore features."
    );
    send_telegram_message($message['chat']['id'], $welcome_text, main_menu(), "Markdown");
}

function callback_query($callback_query) {
    global $REQUIRED_CHANNEL, $WELCOME_IMAGE_PATH, $bot_token;
    $user_id = $callback_query['from']['id'];
    $user = get_user($user_id);
    if (!$user) {
        answer_callback_query($callback_query['id'], "❌ An error occurred. Please try again.", true);
        return;
    }

    $call_data = $callback_query['data'];

    // Handle the "I've Joined" button click
    if ($call_data == "joined_channel") {
        // Re-verify membership using the global function
        if (check_membership($user_id)) {
            mark_user_joined($user_id);
            // Give credits if first time
            if ($user["first_time"]) {
                add_credits($user_id, 4, $user["referred_by"]);
                // Update first_time in DB
                $connection = get_db_connection();
                if ($connection) {
                    try {
                        $stmt = $connection->prepare("UPDATE users SET first_time = FALSE WHERE user_id = ?");
                        $stmt->execute([$user_id]);
                    } catch (PDOException $e) {
                        log_error("Error updating first_time for $user_id: " . $e->getMessage());
                    }
                }
            }
            answer_callback_query($callback_query['id'], "✅ Thanks for joining! Enjoy the bot.");
            $welcome_text = (
                "👋 *Welcome to the osintricx Data Search Bot!*\n"
                . "🔍 Use the buttons below to explore features."
            );
            try {
                edit_message_text($callback_query['message']['chat']['id'], $callback_query['message']['message_id'], $welcome_text, main_menu(), "Markdown");
            } catch (Exception $e) {
                 send_telegram_message($callback_query['message']['chat']['id'], $welcome_text, main_menu(), "Markdown");
            }
        } else {
            answer_callback_query($callback_query['id'], "❌ Please join the channel first!", true);
        }
        return;
    }

    // --- Main Menu Button Handlers (with membership check using global function) ---
    if (in_array($call_data, ["search_number", "search_username", "refer", "buy_credits", "price_list", "balance", "redeem_code"])) {
        // Pass user_id to the global function
        if (!check_membership($user_id)) {
            answer_callback_query($callback_query['id'], "📢 Please join our channel first!", true);
            return;
        }
    }

    if ($user["credits"] <= 0 && in_array($call_data, ["search_number", "search_username"])) {
        answer_callback_query($callback_query['id'], "❌ Not enough credits! Earn more or buy credits.", true);
        return;
    }

    if ($call_data == "search_number") {
        $msg = send_telegram_message($callback_query['message']['chat']['id'], "📞 Enter the phone number to check (e.g., +919999999999):");
        // Registering next step handler is complex in PHP without a framework.
        // You would typically store the state (waiting for input) in the database
        // and check the state when the next message arrives.
        // For this conversion, we'll assume the next message is handled separately.
        answer_callback_query($callback_query['id']);
        // Store state in DB: user_id -> 'waiting_for_number'
        store_user_state($user_id, 'waiting_for_number');

    } elseif ($call_data == "search_username") {
        $msg = send_telegram_message($callback_query['message']['chat']['id'], "👤 Enter the username to search (e.g., john_doe):");
        answer_callback_query($callback_query['id']);
        // Store state in DB: user_id -> 'waiting_for_username'
        store_user_state($user_id, 'waiting_for_username');

    } elseif ($call_data == "refer") {
        $ref_link = gen_ref_link($user_id);
        $text = "📨 Share this link to earn credits:\n\n`$ref_link`\n\n"
              . "🎁 Earn 2 credits for each friend who joins using your link!\n"
              . "Your friends get 4 free credits too!";
        send_telegram_message($callback_query['message']['chat']['id'], $text, main_menu(), "Markdown");
        answer_callback_query($callback_query['id']);

    } elseif ($call_data == "buy_credits") {
        // --- REPLACE '@youradmin' BELOW ---
        $text = "*💳 To purchase credits, please contact the admin:*\n\n@youradmin"; // <--- Replace with your admin contact
        send_telegram_message($callback_query['message']['chat']['id'], $text, main_menu(), "Markdown");
        answer_callback_query($callback_query['id']);

    } elseif ($call_data == "price_list") {
        $price_text = (
            "*💰 Credit Purchase Options:*\n\n"
            . "🔹 4 Credits - ₹10\n"
            . "🔹 20 Credits - ₹40\n"
            . "🔹 50 Credits - ₹99\n\n"
            . "*💳 To purchase, contact:*\n@youradmin" // <-- Replace with your admin contact
        );
        send_telegram_message($callback_query['message']['chat']['id'], $price_text, main_menu(), "Markdown");
        answer_callback_query($callback_query['id']);

    } elseif ($call_data == "balance") {
        answer_callback_query($callback_query['id'], "💰 Balance: " . $user['credits'] . " credits", true);

    // --- NEW: Redeem Code Handler ---
    } elseif ($call_data == "redeem_code") {
        // Ask the user for the redeem code
        send_telegram_message($callback_query['message']['chat']['id'], "🎫 Please enter the redeem code:");
        answer_callback_query($callback_query['id']);
        // Store state so we know what the next message is for
        store_user_state($user_id, 'awaiting_redeem_code');
    }
    // --- END NEW ---

    elseif (str_starts_with($call_data, "/page ")) {
        try {
            $parts = explode(" ", $call_data);
            if (count($parts) < 3) {
                 throw new Exception("Invalid callback data format");
            }
            $query_id = $parts[1];
            $page_id = (int)$parts[2];

            // --- CHANGED: Fetch report from database instead of $cash_reports ---
            $report = null;
            $connection = get_db_connection();
            if ($connection) {
                try {
                    $stmt_fetch = $connection->prepare("SELECT report_data FROM search_results WHERE query_id = ?");
                    $stmt_fetch->execute([$query_id]);
                    $result_row = $stmt_fetch->fetch();
                    if ($result_row) {
                        // Unserialize the stored JSON string back into an array
                        $report = json_decode($result_row['report_data'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                             log_error("JSON decode error for stored report $query_id: " . json_last_error_msg());
                             $report = null; // Treat as not found/expired if decode fails
                        }
                    } else {
                        log_error("Report data not found in DB for query ID: $query_id");
                    }
                } catch (PDOException $e) {
                    log_error("Database error fetching report for ID $query_id: " . $e->getMessage());
                }
            } else {
                 log_error("Failed to get DB connection to fetch report for ID $query_id.");
            }

            if (!$report || !is_array($report)) { // Check if $report is valid
                edit_message_text($callback_query['message']['chat']['id'], $callback_query['message']['message_id'], "❌ Search results expired. Please perform a new search.", null);
                answer_callback_query($callback_query['id']);
                return;
            }
            // --- END CHANGED ---

            // The rest of the pagination logic remains mostly the same
            // Use $report instead of $cash_reports[$query_id]
            $page_id = $page_id % count($report); // Ensure page_id wraps correctly

            $markup = create_inline_keyboard($query_id, $page_id, count($report));
            try {
                // Assuming edit_message_text function exists and works
                edit_message_text($callback_query['message']['chat']['id'], $callback_query['message']['message_id'], $report[$page_id], $markup, "HTML");
            } catch (Exception $e) {
                // Fallback to Markdown
                $fallback_text = str_replace(["<b>", "</b>"], ["*", "*"], $report[$page_id]);
                // Assuming edit_message_text function exists and works
                edit_message_text($callback_query['message']['chat']['id'], $callback_query['message']['message_id'], $fallback_text, $markup, "Markdown");
            }
        } catch (Exception $e) {
            log_error("Error in /page callback: " . $e->getMessage());
            answer_callback_query($callback_query['id'], "❌ An error occurred.", true);
        }
        answer_callback_query($callback_query['id']);
    }
}

// --- Helper function to store user state (waiting for input) ---
function store_user_state($user_id, $state) {
    $connection = get_db_connection();
    if (!$connection) {
        return false;
    }
    try {
        // Assuming you have a `user_states` table: user_id (PK), state, timestamp
        $stmt = $connection->prepare("REPLACE INTO user_states (user_id, state) VALUES (?, ?)");
        $stmt->execute([$user_id, $state]);
        return true;
    } catch (PDOException $e) {
        log_error("Error storing user state for $user_id: " . $e->getMessage());
        return false;
    }
}

// --- Placeholder for perform_search logic ---
// This would be triggered when a user sends a message after being prompted for input.
// You would check the user's state from the DB and call the appropriate search logic.
function handle_user_input($message) {
    global $REQUIRED_CHANNEL, $bot_token;
    $user_id = $message['from']['id'];
    $text = trim($message['text'] ?? '');

    // Get user state
    $connection = get_db_connection();
    if (!$connection) {
        send_telegram_message($message['chat']['id'], "❌ An error occurred. Please try again later.", main_menu());
        return;
    }
    try {
        $stmt = $connection->prepare("SELECT state FROM user_states WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $state_row = $stmt->fetch();
        $state = $state_row ? $state_row['state'] : null;
    } catch (PDOException $e) {
        log_error("Error getting user state for $user_id: " . $e->getMessage());
        send_telegram_message($message['chat']['id'], "❌ An error occurred. Please try again later.", main_menu());
        return;
    }

    if ($state == 'waiting_for_number') {
        perform_search($message, $user_id, "number");
        // Clear state
        clear_user_state($user_id);
    } elseif ($state == 'waiting_for_username') {
        perform_search($message, $user_id, "username");
        // Clear state
        clear_user_state($user_id);

    // --- NEW: Handle Redeem Code Input ---
    } elseif ($state == 'awaiting_redeem_code') {
        $redeem_code = trim($message['text'] ?? '');
        if (empty($redeem_code)) {
            send_telegram_message($message['chat']['id'], "❌ Please provide a valid redeem code.", main_menu());
        } else {
            $redeem_result = process_redeem_code($user_id, $redeem_code);
            if ($redeem_result['success']) {
                 send_telegram_message($message['chat']['id'], "✅ " . $redeem_result['message'], main_menu());
            } else {
                 send_telegram_message($message['chat']['id'], "❌ " . $redeem_result['message'], main_menu());
            }
        }
        // Clear state
        clear_user_state($user_id);
    }
    // --- END NEW ---

    else {
        // Not waiting for input, ignore or show main menu
        // Only send a message if it's not a command or unrelated text
        // You might want to refine this logic
        if ($text !== '' && !str_starts_with($text, '/')) {
             send_telegram_message($message['chat']['id'], "Please use the menu options.", main_menu());
        }
        // If it's a command like /start, it will be handled by the webhook handler
    }
}

function clear_user_state($user_id) {
    $connection = get_db_connection();
    if (!$connection) {
        return false;
    }
    try {
        $stmt = $connection->prepare("DELETE FROM user_states WHERE user_id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        log_error("Error clearing user state for $user_id: " . $e->getMessage());
        return false;
    }
}

function perform_search($message, $user_id, $search_type = "number") {
    global $REQUIRED_CHANNEL, $url, $bot_token; // Add other needed globals
    // Re-verify user and credits before processing using the global function
    // Pass user_id to the global function
    if (!check_membership($user_id)) {
        send_telegram_message($message['chat']['id'], "📢 Please join our channel first. Use /start to begin.", null);
        return;
    }
    $user = get_user($user_id);
    if (!$user || $user["credits"] <= 0) {
        send_telegram_message($message['chat']['id'], "❌ You don't have enough credits to perform a search. Earn more or buy credits.", main_menu());
        return;
    }

    $query = trim($message['text'] ?? '');
    if (!$query) {
        send_telegram_message($message['chat']['id'], "❌ Please enter a valid $search_type.", main_menu());
        return;
    }

    // Optional: Add basic validation
    if ($search_type == "number" && !(str_starts_with($query, "+") && ctype_digit(str_replace(' ', '', substr($query, 1))))) {
         send_telegram_message($message['chat']['id'], "❌ Please enter a valid phone number starting with + (e.g., +919999999999).", main_menu());
         return;
    }

    $query_id = rand(100000, 999999);
    send_telegram_message($message['chat']['id'], "🔍 Searching database... please wait.");

    // Call your API
    // CHANGED: Pass $user_id to generate_report and store the returned report directly in a variable
    $report = generate_report($query, $query_id, $user_id); // Pass $user_id

    if ($report === null || empty($report)) { // CHANGED: Check the returned variable
        send_telegram_message($message['chat']['id'], "❌ No data found for this query or an error occurred.", main_menu());
        return;
    }

    // Deduct credit *after* successful search initiation
    $success = add_credits($user_id, -1); // Deduct 1 credit
    if (!$success) {
        send_telegram_message($message['chat']['id'], "❌ An error occurred while deducting credits.", main_menu());
        return; // Don't send results if credit deduction failed
    }

    // Send the first page of results
    // CHANGED: Create inline keyboard using the count of the returned report
    $markup = create_inline_keyboard($query_id, 0, count($report)); // Use count($report)
    try {
        // CHANGED: Send the first item from the returned report
        send_telegram_message($message['chat']['id'], $report[0], $markup, "HTML");
    } catch (Exception $e) {
        log_error("Error sending HTML message: " . $e->getMessage());
        try {
            // CHANGED: Fallback using the first item from the returned report
            $fallback_text = str_replace(["<b>", "</b>"], ["*", "*"], $report[0]);
            send_telegram_message($message['chat']['id'], $fallback_text, $markup, "Markdown");
        } catch (Exception $e2) {
            log_error("Fallback send also failed: " . $e2->getMessage());
            send_telegram_message($message['chat']['id'], "❌ An error occurred while formatting the results.", main_menu());
            return; // Stop if both sending methods fail
        }
    }

    // Inform user about credit deduction and remaining balance
    $user_updated = get_user($user_id); // Get updated credit balance
    $remaining_credits = $user_updated ? $user_updated["credits"] : "Unknown";
    send_telegram_message(
        $message['chat']['id'],
        "✅ Search completed! You have *$remaining_credits* credits remaining.",
        main_menu(),
        "Markdown"
    );
    // At the end of this function, $report goes out of scope and is deleted from memory.
}

// --- Helper functions for Telegram API calls (using cURL) ---
function send_telegram_message($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    global $bot_token;
    $apiUrl = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    $params = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    if ($parse_mode) {
        $params['parse_mode'] = $parse_mode;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) {
        log_error("Error sending message to chat $chat_id. HTTP $httpCode. Response: $response");
    }
    // Optionally return message ID or other info from response
    return json_decode($response, true); // Return the full response
}

function answer_callback_query($callback_query_id, $text = '', $show_alert = false) {
    global $bot_token;
    $apiUrl = "https://api.telegram.org/bot{$bot_token}/answerCallbackQuery";
    $params = [
        'callback_query_id' => $callback_query_id,
        'text' => $text,
        'show_alert' => $show_alert
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    // Optionally check response for errors
}

function edit_message_text($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = null) {
    global $bot_token;
    $apiUrl = "https://api.telegram.org/bot{$bot_token}/editMessageText";
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    if ($parse_mode) {
        $params['parse_mode'] = $parse_mode;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) {
        $error_response = json_decode($response, true);
        if (isset($error_response['error_code']) && $error_response['error_code'] == 400 && strpos($error_response['description'], 'message is not modified') !== false) {
            // This is an expected error if the text hasn't changed, ignore or log differently
            // log_info("Message not modified for chat $chat_id, message $message_id");
        } else {
             log_error("Error editing message for chat $chat_id, message $message_id. HTTP $httpCode. Response: $response");
             throw new Exception("Failed to edit message");
        }
    }
    // Optionally return the updated message object
    return json_decode($response, true);
}

// --- NEW: Redeem Code Functions ---
/**
 * Processes a redeem code entered by a user.
 * @param int $user_id The ID of the user redeeming the code.
 * @param string $code The redeem code string.
 * @return array An associative array with 'success' (bool) and 'message' (string).
 */
function process_redeem_code($user_id, $code) {
    $connection = get_db_connection();
    if (!$connection) {
        return ['success' => false, 'message' => 'Database connection error. Please try again later.'];
    }

    try {
        // 1. Check if the code exists and is valid in `redeem_codes` table
        $stmt_check_code = $connection->prepare("
            SELECT id, credits, max_uses, current_uses, expires_at
            FROM redeem_codes
            WHERE code = ?
        ");
        $stmt_check_code->execute([$code]);
        $code_data = $stmt_check_code->fetch();

        if (!$code_data) {
            return ['success' => false, 'message' => 'Invalid or non-existent redeem code.'];
        }

        // 2. Check if the code has expired
        $now = new DateTime();
        if ($code_data['expires_at'] !== null) {
            $expiry_date = new DateTime($code_data['expires_at']);
            if ($now > $expiry_date) {
                return ['success' => false, 'message' => 'This redeem code has expired.'];
            }
        }

        // 3. Check if the maximum usage limit has been reached
        if ($code_data['current_uses'] >= $code_data['max_uses']) {
            return ['success' => false, 'message' => 'This redeem code has already been used the maximum number of times.'];
        }

        // 4. Check if the user has already used this specific code (in `used_redeem_codes` table)
        $stmt_check_used = $connection->prepare("
            SELECT id
            FROM used_redeem_codes
            WHERE user_id = ? AND code_id = ?
        ");
        $stmt_check_used->execute([$user_id, $code_data['id']]);
        $already_used = $stmt_check_used->fetch();

        if ($already_used) {
            return ['success' => false, 'message' => 'You have already used this redeem code.'];
        }

        // --- If all checks pass, proceed to redeem ---
        // 5. Start a database transaction
        $connection->beginTransaction();

        // 6. Add credits to the user's account
        $stmt_add_credits = $connection->prepare("UPDATE users SET credits = credits + ? WHERE user_id = ?");
        $stmt_add_credits->execute([$code_data['credits'], $user_id]);

        // 7. Record the usage in `used_redeem_codes` table
        $stmt_record_usage = $connection->prepare("
            INSERT INTO used_redeem_codes (user_id, code_id)
            VALUES (?, ?)
        ");
        $stmt_record_usage->execute([$user_id, $code_data['id']]);

        // 8. Increment the `current_uses` counter in `redeem_codes` table
        $stmt_increment_use = $connection->prepare("
            UPDATE redeem_codes
            SET current_uses = current_uses + 1
            WHERE id = ?
        ");
        $stmt_increment_use->execute([$code_data['id']]);

        // 9. Commit the transaction
        $connection->commit();

        // 10. Return success message
        return [
            'success' => true,
            'message' => "Redeemed successfully! {$code_data['credits']} credits have been added to your account."
        ];

    } catch (PDOException $e) {
        // Rollback transaction on any database error
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        log_error("Database error processing redeem code '$code' for user $user_id: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred while processing the code. Please try again later.'];
    }
}
// --- END NEW ---
// --- End of File ---
?>