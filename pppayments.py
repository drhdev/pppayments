#!/usr/bin/env python3
# Name: pppayments.py
# Version: 0.2
# Author: mou
# Description: This script reads PayPal transaction logs from an SQLite database, creates daily summaries,
# sends these summaries to a Telegram bot, and records the time the message was sent.
# It prevents duplicate entries and manages database size by deleting entries older than user-defined days.
# It also supports viewing logs via the -v option and handles network errors with retry logic.
# Designed for daily execution via a cron job.

import sqlite3
from datetime import datetime, timedelta
import os
import sys
import argparse
import time
import requests
from dotenv import load_dotenv
import logging
from logging.handlers import RotatingFileHandler

# ---------------------- User Configurable Variables ---------------------- #
# Working directory where the script is executed
WORKDIR = os.path.dirname(os.path.abspath(__file__))

# Database and log file paths
DATABASE_PATH = os.path.join(WORKDIR, 'gpldlpppayments_db', 'gpldlpppayments.db')
LOG_FILE_NAME = 'pppayments.log'

# Number of days after which old records are deleted
DAYS_TO_KEEP = 30

# Telegram Bot configuration
TELEGRAM_BOT_TOKEN = os.getenv('TELEGRAM_BOT_TOKEN')
TELEGRAM_CHAT_ID = os.getenv('TELEGRAM_CHAT_ID')

# Rotating log file settings
LOG_MAX_SIZE_BYTES = 5 * 1024 * 1024  # 5 MB per log file
LOG_BACKUP_COUNT = 5  # Number of log files to keep

# Network settings for retry mechanism
MAX_RETRIES = 3  # Maximum number of retry attempts
RETRY_WAIT_INTERVAL = 5  # Time (in seconds) to wait between retries
# ------------------------------------------------------------------------- #

# Set up logging
logger = logging.getLogger('pppayments.py')
logger.setLevel(logging.DEBUG)
handler = RotatingFileHandler(LOG_FILE_NAME, maxBytes=LOG_MAX_SIZE_BYTES, backupCount=LOG_BACKUP_COUNT)
formatter = logging.Formatter('%(asctime)s - %(name)s - %(levelname)s - %(message)s')
handler.setFormatter(formatter)
logger.addHandler(handler)

# Load environment variables
load_dotenv()

# Argument Parser for command-line options
parser = argparse.ArgumentParser(description='PayPal payments processing script with Telegram notifications.')
parser.add_argument('-v', '--view-log', action='store_true', help='View the log file entries.')
args = parser.parse_args()

# If -v or --view-log is passed, display the log contents and exit
if args.view_log:
    with open(LOG_FILE_NAME, 'r') as log_file:
        print(log_file.read())
    sys.exit(0)


def initialize_database_connection():
    """Initialize the database connection and create necessary tables if not exists."""
    try:
        conn = sqlite3.connect(DATABASE_PATH)
        cursor = conn.cursor()
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS daily_summaries (
                date TEXT PRIMARY KEY,
                total_transactions INTEGER,
                total_amount REAL,
                daily_summary_message TEXT,
                telegram_sent TEXT DEFAULT NULL
            )
        ''')
        return conn, cursor
    except sqlite3.Error as e:
        logger.error(f"Database initialization failed: {e}")
        sys.exit(1)


def get_previous_day_summary(cursor):
    """Retrieve the summary of the previous day."""
    prev_day = datetime.now() - timedelta(days=1)
    prev_day_str = prev_day.strftime('%a, %d %B %Y')  # e.g., Mon, 27 September 2024

    cursor.execute('SELECT date FROM daily_summaries WHERE date = ?', (prev_day_str,))
    if cursor.fetchone():
        logger.info(f"Duplicate entry for date {prev_day_str}. Exiting.")
        return None, None, None

    cursor.execute('''
        SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM payments
        WHERE strftime('%Y-%m-%d', create_time) = ?
    ''', (prev_day.strftime('%Y-%m-%d'),))  # Use ISO format for consistency

    total_transactions, total_amount = cursor.fetchone()
    return prev_day_str, total_transactions, round(total_amount, 2)


def create_summary_message(date, total_transactions, total_amount):
    """Create the daily summary message based on transaction data."""
    if total_transactions == 0:
        total_transactions = "ERROR"
        total_amount = "ERROR"
        return f"On {date} there were ERROR PayPal transactions with a total income of USD ERROR."
    return f"On {date} there were {total_transactions} PayPal transactions with a total income of USD {total_amount:.2f}."


def insert_daily_summary(cursor, date, total_transactions, total_amount, summary_message):
    """Insert the daily summary into the database."""
    try:
        cursor.execute('''
            INSERT INTO daily_summaries (date, total_transactions, total_amount, daily_summary_message)
            VALUES (?, ?, ?, ?)
        ''', (date, total_transactions, total_amount, summary_message))
    except sqlite3.Error as e:
        logger.error(f"Failed to insert daily summary for {date}: {e}")
        return False
    return True


def send_telegram_message(message):
    """Send a message to the Telegram bot with retry logic and return success status."""
    url = f'https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage'
    payload = {'chat_id': TELEGRAM_CHAT_ID, 'text': message}

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = requests.post(url, data=payload)
            if response.status_code == 200:
                return True
            else:
                logger.warning(f"Telegram message failed (Status {response.status_code}). Attempt {attempt}/{MAX_RETRIES}.")
        except requests.RequestException as e:
            logger.error(f"Network error on attempt {attempt}/{MAX_RETRIES}: {e}")

        if attempt < MAX_RETRIES:
            logger.info(f"Retrying in {RETRY_WAIT_INTERVAL} seconds...")
            time.sleep(RETRY_WAIT_INTERVAL)

    return False


def update_telegram_status(cursor, date):
    """Update the status of Telegram message sent time in the database."""
    try:
        sent_time = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        cursor.execute('''
            UPDATE daily_summaries
            SET telegram_sent = ?
            WHERE date = ?
        ''', (sent_time, date))
    except sqlite3.Error as e:
        logger.error(f"Failed to update Telegram status for {date}: {e}")


def cleanup_old_entries(cursor):
    """Delete entries older than the user-defined number of days from payments and summaries tables."""
    cutoff_date = (datetime.now() - timedelta(days=DAYS_TO_KEEP)).strftime('%Y-%m-%d')
    try:
        cursor.execute("DELETE FROM payments WHERE strftime('%Y-%m-%d', create_time) < ?", (cutoff_date,))
        cursor.execute('DELETE FROM daily_summaries WHERE date < ?', (cutoff_date,))
    except sqlite3.Error as e:
        logger.error(f"Failed to clean up old entries: {e}")


def main():
    os.chdir(WORKDIR)  # Set working directory
    conn, cursor = initialize_database_connection()

    prev_day_str, total_transactions, total_amount = get_previous_day_summary(cursor)
    if not prev_day_str:
        conn.close()
        return

    summary_message = create_summary_message(prev_day_str, total_transactions, total_amount)

    if insert_daily_summary(cursor, prev_day_str, total_transactions, total_amount, summary_message):
        if send_telegram_message(summary_message):
            update_telegram_status(cursor, prev_day_str)

    cleanup_old_entries(cursor)
    conn.commit()
    conn.close()


if __name__ == "__main__":
    main()

