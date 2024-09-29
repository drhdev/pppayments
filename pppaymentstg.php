<?php
/*
 * Script Name: pppaymentstg.php
 * Version: 0.1
 * 
 * Description:
 * - Reads PayPal transaction logs from the MySQL database.
 * - Creates daily summaries, sends these summaries to a Telegram bot, and records the time the message was sent.
 * - Prevents duplicate entries and manages database size by deleting entries older than a user-defined number of days.
 * - Supports viewing logs via the -v option and handles network errors with retry logic.
 * - Designed for daily execution via a cron job.
 */

// Prevent script from running in a web context
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.0 403 Forbidden');
    echo "This script can only be run from the command line or a cron job.";
    exit;
}

// Load Composer's autoload and environment variables
require __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use PDO;

// ------------------- User Configurable Variables ------------------- //
// Load environment variables from .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Database Configuration
define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));

// Log file path and settings
define('LOG_FILE', 'pppayments.log');
define('DAYS_TO_KEEP', 730); // Number of days after which old records are deleted

// Telegram Bot Configuration
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN'));
define('TELEGRAM_CHAT_ID', getenv('TELEGRAM_CHAT_ID'));

// Retry settings
define('MAX_RETRIES', 3); // Maximum number of retry attempts
define('RETRY_WAIT_INTERVAL', 5); // Time in seconds to wait between retries

// ---------------------- Helper Functions -------------------------- //

/**
 * Connect to the MySQL database.
 */
function connectDatabase() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Failed to connect to the database: " . $e->getMessage());
        return null;
    }
}

/**
 * Retrieve the summary of the previous day.
 */
function getPreviousDaySummary($pdo) {
    $prevDay = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments WHERE DATE(create_time) = ?");
    $stmt->execute([$prevDay]);
    $result = $stmt->fetch(PDO::FETCH_NUM);

    $stmt = $pdo->prepare("SELECT date FROM daily_summaries WHERE date = ?");
    $stmt->execute([$prevDay]);
    if ($stmt->fetch()) {
        error_log("Duplicate entry for date $prevDay. Exiting.");
        return [null, null, null];
    }
    
    return [$prevDay, $result[0], round($result[1], 2)];
}

/**
 * Create the daily summary message based on transaction data.
 */
function createSummaryMessage($date, $totalTransactions, $totalAmount) {
    if ($totalTransactions == 0) {
        return "On $date there were ERROR PayPal transactions with a total income of USD ERROR.";
    }
    return "On $date there were $totalTransactions PayPal transactions with a total income of USD $totalAmount.";
}

/**
 * Insert the daily summary into the database.
 */
function insertDailySummary($pdo, $date, $totalTransactions, $totalAmount, $summaryMessage) {
    $stmt = $pdo->prepare("INSERT INTO daily_summaries (date, total_transactions, total_amount, daily_summary_message) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$date, $totalTransactions, $totalAmount, $summaryMessage]);
}

/**
 * Send a message to the Telegram bot.
 */
function sendTelegramMessage($message) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $payload = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $message];

    for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
        try {
            $response = file_get_contents($url . '?' . http_build_query($payload));
            $data = json_decode($response, true);
            if ($data && $data['ok']) {
                return true;
            } else {
                error_log("Telegram message failed. Attempt $attempt/" . MAX_RETRIES);
            }
        } catch (Exception $e) {
            error_log("Network error on attempt $attempt/" . MAX_RETRIES . ": " . $e->getMessage());
        }

        if ($attempt < MAX_RETRIES) {
            sleep(RETRY_WAIT_INTERVAL);
        }
    }

    return false;
}

/**
 * Update the status of Telegram message sent time in the database.
 */
function updateTelegramStatus($pdo, $date) {
    $sentTime = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE daily_summaries SET telegram_sent = ? WHERE date = ?");
    return $stmt->execute([$sentTime, $date]);
}

/**
 * Delete old entries from the database.
 */
function cleanupOldEntries($pdo) {
    $cutoffDate = date('Y-m-d', strtotime('-' . DAYS_TO_KEEP . ' days'));
    $pdo->prepare("DELETE FROM payments WHERE DATE(create_time) < ?")->execute([$cutoffDate]);
    $pdo->prepare("DELETE FROM daily_summaries WHERE date < ?")->execute([$cutoffDate]);
}

/**
 * Main function.
 */
function main() {
    $pdo = connectDatabase();
    if (!$pdo) {
        exit("Failed to connect to the database");
    }

    list($prevDay, $totalTransactions, $totalAmount) = getPreviousDaySummary($pdo);
    if (!$prevDay) {
        return;
    }

    $summaryMessage = createSummaryMessage($prevDay, $totalTransactions, $totalAmount);
    if (insertDailySummary($pdo, $prevDay, $totalTransactions, $totalAmount, $summaryMessage)) {
        if (sendTelegramMessage($summaryMessage)) {
            updateTelegramStatus($pdo, $prevDay);
        }
    }

    cleanupOldEntries($pdo);
}

// -------------------- Execute Script -------------------- //
main();
?>
