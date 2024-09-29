<?php
/*
 * Script Name: pppaymentswh.php
 * Version: 1.3
 * 
 * Description:
 * - Listens for incoming PayPal webhook notifications.
 * - Parses the JSON payload from PayPal and extracts relevant payment information.
 * - Stores payment details (ID, status, amount, currency, and creation time) in an SQLite database.
 * - Logs any errors encountered during processing.
 */

// ===========================
//         SETTINGS
// ===========================
define('DB_PATH', '/var/shared/gpldlpppayments_db/gpldlpppayments.db');  // Path to the SQLite database
define('DEFAULT_LOG_DIR', '/var/log/apache2/');                          // Default log directory for PHP scripts in Ubuntu 22.04 with Apache
define('LOG_FILE_NAME', 'pppaymentswh.log');                             // Default log file name
define('LOG_FILE', DEFAULT_LOG_DIR . LOG_FILE_NAME);                     // Full path to the log file
define('PAYPAL_EVENT_TYPE', 'PAYMENT.SALE.COMPLETED');                   // PayPal event type to listen for
define('REQUIRED_REQUEST_METHOD', 'POST');                               // Required request method for this script

// ===========================
//   SET UP ERROR LOGGING
// ===========================
ini_set('log_errors', 'On');
ini_set('error_log', LOG_FILE);

/**
 * Establishes and returns a connection to the SQLite database.
 * 
 * @return SQLite3|false Database connection object or false on failure.
 */
function connectDatabase() {
    $database = new SQLite3(DB_PATH);
    if (!$database) {
        error_log("Failed to connect to the database: " . $database->lastErrorMsg());
        return false;
    }
    return $database;
}

/**
 * Creates the payments table if it does not exist.
 * 
 * @param SQLite3 $database Database connection object.
 * @return bool True on success, false on failure.
 */
function createPaymentsTable($database) {
    $query = <<<SQL
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_id TEXT NOT NULL,
    status TEXT NOT NULL,
    amount REAL NOT NULL,
    currency TEXT NOT NULL,
    create_time DATETIME NOT NULL
)
SQL;

    if (!$database->exec($query)) {
        error_log("Failed to create table: " . $database->lastErrorMsg());
        return false;
    }
    return true;
}

/**
 * Inserts a payment record into the database.
 * 
 * @param SQLite3 $database Database connection object.
 * @param object $data Parsed JSON data from the PayPal webhook.
 * @return bool True on success, false on failure.
 */
function insertPayment($database, $data) {
    $stmt = $database->prepare("INSERT INTO payments (payment_id, status, amount, currency, create_time) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bindValue(1, $data->resource->id, SQLITE3_TEXT);
        $stmt->bindValue(2, $data->resource->state, SQLITE3_TEXT);
        $stmt->bindValue(3, $data->resource->amount->total, SQLITE3_FLOAT);
        $stmt->bindValue(4, $data->resource->amount->currency, SQLITE3_TEXT);
        $stmt->bindValue(5, $data->resource->create_time, SQLITE3_TEXT);
        
        if ($stmt->execute()) {
            return true;
        } else {
            error_log("Failed to insert data: " . $database->lastErrorMsg());
        }
    } else {
        error_log("Failed to prepare SQL statement: " . $database->lastErrorMsg());
    }
    return false;
}

/**
 * Processes the PayPal webhook payload.
 */
function processWebhook() {
    if ($_SERVER['REQUEST_METHOD'] !== REQUIRED_REQUEST_METHOD) {
        error_log("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        echo 'This script only accepts ' . REQUIRED_REQUEST_METHOD . ' requests';
        return;
    }

    $payload = file_get_contents('php://input');
    $data = json_decode($payload);

    if (!$data || $data->event_type !== PAYPAL_EVENT_TYPE) {
        error_log("Received irrelevant webhook event or bad data");
        echo 'No relevant webhook event';
        return;
    }

    // Connect to the database
    $database = connectDatabase();
    if (!$database) {
        exit('Failed to connect to the database');
    }

    // Create table if not exists
    if (!createPaymentsTable($database)) {
        exit('Failed to create table');
    }

    // Insert payment data
    if (insertPayment($database, $data)) {
        echo 'Payment recorded successfully';
    } else {
        echo 'Failed to record payment';
    }

    // Close the database connection
    $database->close();
}

// Main entry point for processing the webhook
processWebhook();

?>
