<?php
/*
 * Script Name: pppaymentswh.php
 * Version: 0.2
 * 
 * Description:
 * - Listens for incoming PayPal webhook notifications.
 * - Parses the JSON payload from PayPal and extracts relevant payment information.
 * - Stores payment details (ID, status, amount, currency, and creation time) in a MySQL database using PDO.
 * - Uses .env file for secure storage of database credentials.
 * - Logs any errors encountered during processing.
 */

require __DIR__ . '/../vendor/autoload.php'; // Include Composer's autoload (make sure path is correct)

use Dotenv\Dotenv;

// ===========================
//    LOAD CONFIGURATIONS
// ===========================
$dotenv = Dotenv::createImmutable(__DIR__ . '/../'); // Load .env file from parent directory (outside web root)
$dotenv->load(); // Load environment variables from the .env file

// Retrieve environment variables securely
define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('LOG_FILE', '/var/log/apache2/pppaymentswh.log'); // Default log file for errors
define('PAYPAL_EVENT_TYPE', 'PAYMENT.SALE.COMPLETED');  // PayPal event type to listen for
define('REQUIRED_REQUEST_METHOD', 'POST');             // Required request method for this script

// ===========================
//   SET UP ERROR LOGGING
// ===========================
ini_set('log_errors', 'On');
ini_set('error_log', LOG_FILE);

/**
 * Establish a connection to the MySQL database using PDO.
 * 
 * @return PDO|null PDO connection object or null on failure.
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
 * Creates the payments table if it doesn't exist.
 * 
 * @param PDO $pdo PDO connection object.
 * @return bool True on success, false on failure.
 */
function createPaymentsTable($pdo) {
    $query = "
    CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payment_id VARCHAR(255) NOT NULL,
        status VARCHAR(50) NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        currency VARCHAR(10) NOT NULL,
        create_time DATETIME NOT NULL
    )";
    
    try {
        $pdo->exec($query);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to create table: " . $e->getMessage());
        return false;
    }
}

/**
 * Inserts a payment record into the database.
 * 
 * @param PDO $pdo PDO connection object.
 * @param object $data Parsed JSON data from the PayPal webhook.
 * @return bool True on success, false on failure.
 */
function insertPayment($pdo, $data) {
    $query = "INSERT INTO payments (payment_id, status, amount, currency, create_time) 
              VALUES (:payment_id, :status, :amount, :currency, :create_time)";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':payment_id', $data->resource->id);
        $stmt->bindParam(':status', $data->resource->state);
        $stmt->bindParam(':amount', $data->resource->amount->total);
        $stmt->bindParam(':currency', $data->resource->amount->currency);
        $stmt->bindParam(':create_time', $data->resource->create_time);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Failed to insert data: " . $e->getMessage());
        return false;
    }
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
    $pdo = connectDatabase();
    if (!$pdo) {
        exit('Failed to connect to the database');
    }

    // Create table if not exists
    if (!createPaymentsTable($pdo)) {
        exit('Failed to create table');
    }

    // Insert payment data
    if (insertPayment($pdo, $data)) {
        echo 'Payment recorded successfully';
    } else {
        echo 'Failed to record payment';
    }
}

// Main entry point for processing the webhook
processWebhook();

?>
