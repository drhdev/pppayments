# pppayments
Two PHP scripts...

- Python script not needed anymore?
- Add Weekly and monthly payments summary at 1st day of new week and 1st day of new month with a dedicated script that calculates the values in the database. 


### Directory Structure

```
/var/www/webhooks.example.com/         # Subdomain project root directory
├── .env                               # Environment configuration file (securely stored here)
├── vendor/                            # Composer dependencies folder (generated by Composer)
│   └── ...                            # Composer packages and autoload files
├── pppayments/                        # Project folder containing PHP scripts
│   ├── pppaymentswh.php               # Main PayPal webhook script
│   ├── pppaymentstg.php               # Telegram notification script
│   ├── composer.json                  # Composer configuration file
│   ├── .env.example                   # Template for the .env file
│   └── ...
```

### Explanation of Key Folders and Files:
- **`/var/www/webhooks.example.com/`**:
  - This is the root directory for the subdomain `webhooks.example.com`.
  - **`.env`**: Securely stores environment variables (database credentials, Telegram bot tokens, etc.).
  
- **`vendor/`**:
  - Contains all Composer dependencies (e.g., `vlucas/phpdotenv`).
  
- **`pppayments/`**:
  - Contains the PHP scripts for handling PayPal webhooks and Telegram notifications.
  
  - **`pppaymentswh.php`**: The main PayPal webhook handler script.
  - **`pppaymentstg.php`**: The script that generates daily summaries and sends notifications to Telegram.
  - **`composer.json`**: Defines all required Composer packages for the project.
  - **`.env.example`**: A template environment file to guide users in setting up their own `.env` file.

### Important Points:
1. The `.env` file is stored directly in the root directory of `webhooks.example.com` (`/var/www/webhooks.example.com/.env`).
2. The PHP scripts are placed inside the `pppayments` folder, and the directory structure is configured to keep sensitive files secure and separate from the main WordPress site on `example.com`.

This structure helps maintain a clean separation between the main WordPress site (`example.com`) and the webhook handling scripts on `webhooks.example.com`.

### Setting Up `webhooks.example.com` as a Subdomain with SSL for PHP Webhook Scripts

If you want to set up a separate subdomain (`webhooks.example.com`) for the PHP webhook scripts while keeping your main `example.com` domain configured for WordPress, follow these steps to configure the subdomain with SSL using Apache.

### Step 1: Create a New Directory for `webhooks.example.com` and Install the Repository

1. **Create the directory structure** for the subdomain:

   ```bash
   sudo mkdir -p /var/www/webhooks.example.com
   ```

2. **Clone the GitHub repository** directly into the newly created directory:

   ```bash
   sudo git clone https://github.com/drhdev/pppayments.git /var/www/webhooks.example.com/pppayments
   ```

   This command will clone your project files into `/var/www/webhooks.example.com/pppayments`.

3. **Navigate to the cloned repository**:

   ```bash
   cd /var/www/webhooks.example.com/pppayments
   ```

4. **Run Composer to Install Dependencies**:

   If you haven’t installed Composer on your server yet, do so with:

   ```bash
   sudo apt update
   sudo apt install composer
   ```

   Then, run Composer in your project directory:

   ```bash
   sudo composer install
   ```

5. **Create the `.env` file** in the `webhooks.example.com` directory:

   ```bash
   sudo cp .env.example ../.env
   ```

6. **Edit the `.env` file** to configure your database and Telegram credentials:

   ```bash
   sudo nano ../.env
   ```

   Update it with your actual values:

   ```
   DB_HOST=127.0.0.1
   DB_NAME=your_database_name
   DB_USER=your_username
   DB_PASS=your_password

   TELEGRAM_BOT_TOKEN=your_telegram_bot_token
   TELEGRAM_CHAT_ID=your_telegram_chat_id
   ```

### Step 2: Set Up Apache for the New Subdomain

1. **Create a new Apache configuration file** for `webhooks.example.com`:

   ```bash
   sudo nano /etc/apache2/sites-available/webhooks.example.com.conf
   ```

2. **Add the following configuration** to the file:

   ```apache
   <VirtualHost *:80>
       ServerAdmin webmaster@webhooks.example.com
       DocumentRoot /var/www/webhooks.example.com/pppayments
       ServerName webhooks.example.com

       <Directory /var/www/webhooks.example.com/pppayments>
           AllowOverride All
           Require all granted
       </Directory>

       ErrorLog ${APACHE_LOG_DIR}/webhooks_example_com_error.log
       CustomLog ${APACHE_LOG_DIR}/webhooks_example_com_access.log combined
   </VirtualHost>
   ```

3. **Enable the new site** and the `rewrite` module (if not already enabled):

   ```bash
   sudo a2ensite webhooks.example.com.conf
   sudo a2enmod rewrite
   ```

4. **Restart Apache** to apply the changes:

   ```bash
   sudo systemctl restart apache2
   ```

### Step 3: Set Up SSL with Certbot for the Subdomain

1. **Install Certbot and the Apache plugin** (if not already installed):

   ```bash
   sudo apt update
   sudo apt install certbot python3-certbot-apache
   ```

2. **Generate an SSL certificate** for `webhooks.example.com`:

   ```bash
   sudo certbot --apache -d webhooks.example.com
   ```

   Follow the prompts to complete the certificate installation. Certbot will automatically update your Apache configuration to use HTTPS.

3. **Verify SSL is enabled**:

   After Certbot completes, it should have automatically set up your Apache configuration to redirect HTTP traffic to HTTPS. Your configuration file (`/etc/apache2/sites-available/webhooks.example.com.conf`) should now look similar to this:

   ```apache
   <VirtualHost *:80>
       ServerAdmin webmaster@webhooks.example.com
       DocumentRoot /var/www/webhooks.example.com/pppayments
       ServerName webhooks.example.com

       <Directory /var/www/webhooks.example.com/pppayments>
           AllowOverride All
           Require all granted
       </Directory>

       ErrorLog ${APACHE_LOG_DIR}/webhooks_example_com_error.log
       CustomLog ${APACHE_LOG_DIR}/webhooks_example_com_access.log combined

       RewriteEngine on
       RewriteCond %{SERVER_NAME} =webhooks.example.com
       RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
   </VirtualHost>

   <VirtualHost *:443>
       ServerAdmin webmaster@webhooks.example.com
       DocumentRoot /var/www/webhooks.example.com/pppayments
       ServerName webhooks.example.com

       <Directory /var/www/webhooks.example.com/pppayments>
           AllowOverride All
           Require all granted
       </Directory>

       ErrorLog ${APACHE_LOG_DIR}/webhooks_example_com_ssl_error.log
       CustomLog ${APACHE_LOG_DIR}/webhooks_example_com_ssl_access.log combined

       SSLEngine on
       SSLCertificateFile /etc/letsencrypt/live/webhooks.example.com/fullchain.pem
       SSLCertificateKeyFile /etc/letsencrypt/live/webhooks.example.com/privkey.pem
       Include /etc/letsencrypt/options-ssl-apache.conf
   </VirtualHost>
   ```

### Step 4: Secure the `.env` File and Project Directory

1. **Set correct permissions** on the `.env` file to ensure that only the web server user (`www-data`) can read it:

   ```bash
   sudo chmod 600 ../.env
   sudo chown www-data:www-data ../.env
   ```

2. **Ensure the webhooks project directory** and files have the right ownership:

   ```bash
   sudo chown -R www-data:www-data /var/www/webhooks.example.com
   ```

### Step 5: Test the Webhook Setup

1. **Verify HTTPS Access**:

   Open `https://webhooks.example.com` in your browser to make sure the SSL certificate is working.

2. **Send a Test Webhook**:

   If you have a tool like `curl`, send a POST request to `https://webhooks.example.com/pppaymentswh.php` to verify that the script processes incoming requests correctly:

   ```bash
   curl -X POST https://webhooks.example.com/pppaymentswh.php -d '{"event_type":"PAYMENT.SALE.COMPLETED", "resource":{"id":"123", "state":"completed", "amount":{"total":"10.00", "currency":"USD"}, "create_time":"2024-09-29T12:34:56Z"}}' -H "Content-Type: application/json"
   ```

3. **Check Apache Logs**:

   If the request fails, check your Apache logs for errors:

   ```bash
   tail -f /var/log/apache2/webhooks_example_com_error.log
   ```

### Step 6: Update DNS Settings for `webhooks.example.com`

1. **Create a DNS `A` record** pointing `webhooks.example.com` to your server’s IP address.
   
   - For example, if your server’s IP is `192.168.1.100`, create an `A` record like this:
     ```
     webhooks.example.com    A    192.168.1.100
     ```

2. **Wait for DNS propagation**, which might take a few minutes.

### Final Configuration Notes

- Your `example.com` WordPress site should continue to work without any changes.
- `webhooks.example.com` will now be a separate subdomain with its own directory and SSL configuration.
- Ensure that `webhooks.example.com` is only used for webhook scripts and sensitive services.

This setup keeps your main domain (`example.com`) and the subdomain (`webhooks.example.com`) separate, providing a clean and secure environment for your webhook-related scripts.


### Setting up a cronjob for pppaymentstg.php

To set up a cron job for `pppaymentstg.php` to run at a scheduled time (e.g., once daily), follow these instructions. Cron is a powerful tool in Unix/Linux-based systems that allows you to schedule tasks, such as executing scripts at specified intervals.

### Step 1: Determine the PHP Path

First, identify the path to your PHP executable. This can vary depending on your server setup. Run the following command to get the PHP path:

```bash
which php
```

This will typically return a path like `/usr/bin/php` or `/usr/local/bin/php`. Make a note of this path, as you'll need it for the cron job.

### Step 2: Verify `pppaymentstg.php` Script Permissions

Ensure that the script `pppaymentstg.php` has executable permissions and that the user running the cron job has access to the script:

```bash
sudo chmod +x /var/www/example.com/pppayments/pppaymentstg.php
```

Make sure the owner of the script matches the user running the cron job (typically `www-data` for Apache).

### Step 3: Open the Crontab for the Desired User

Typically, you will want to set the cron job for the user running the web server (e.g., `www-data`), or if you are running it as your own user, use your own crontab:

- **For the current user**:
  
  ```bash
  crontab -e
  ```

- **For a specific user** (e.g., `www-data`):

  ```bash
  sudo crontab -u www-data -e
  ```

This command opens the crontab editor for the specified user. If this is the first time setting up a cron job, you might be prompted to choose an editor (e.g., `nano`).

### Step 4: Add the Cron Job to the Crontab

Add the following line to the crontab to schedule `pppaymentstg.php` to run once daily at a specific time (e.g., 11:00 PM). Adjust the time as needed:

```bash
0 23 * * * /usr/bin/php /var/www/example.com/pppayments/pppaymentstg.php >> /var/www/example.com/pppayments/cron.log 2>&1
```

**Explanation**:

- `0 23 * * *` — This specifies that the script should run at **11:00 PM** every day:
  - `0`: Minute (0th minute)
  - `23`: Hour (23rd hour, which is 11:00 PM)
  - `*`: Day of the month (every day)
  - `*`: Month (every month)
  - `*`: Day of the week (every day of the week)

- `/usr/bin/php` — Path to the PHP executable. Replace this with the actual PHP path obtained earlier.
- `/var/www/example.com/pppayments/pppaymentstg.php` — Full path to your `pppaymentstg.php` script.
- `>> /var/www/example.com/pppayments/cron.log 2>&1` — Redirects both **standard output** and **standard error** to a log file (`cron.log`), located in the same directory as the script. This is useful for debugging cron job issues.

### Step 5: Save and Exit the Crontab

- If you're using `nano`, save and exit by pressing `Ctrl + X`, then `Y` to confirm changes, and `Enter`.
- For `vi` or `vim`, use `:wq` to write and quit.

### Step 6: Verify That the Cron Job Is Set

You can view your current cron jobs by running:

```bash
crontab -l
```

This command will list all cron jobs for the current user. You should see the entry you just added.

### Step 7: Manually Test the Script (Optional)

To verify that the `pppaymentstg.php` script works correctly, run it manually from the command line:

```bash
/usr/bin/php /var/www/example.com/pppayments/pppaymentstg.php
```

Check the output for any errors or issues. If it runs successfully, your cron job should work as expected.

### Step 8: Check the Log File

Once the cron job runs, check the `cron.log` file to see the output and confirm that everything is running smoothly:

```bash
cat /var/www/example.com/pppayments/cron.log
```

If you encounter any issues, review this log file to see error messages or output from the script.

### Troubleshooting Tips

- **Cron Jobs Not Running**:
  - Ensure that the `cron` service is running:
    ```bash
    sudo systemctl status cron
    ```
  - If it's not running, start it:
    ```bash
    sudo systemctl start cron
    ```

- **Permission Issues**:
  - If the script or the `.env` file is not readable, you may need to adjust permissions or run the cron job as the correct user (e.g., `www-data`).

- **PHP Version Conflicts**:
  - If you have multiple PHP versions installed, make sure you specify the correct PHP executable path (e.g., `/usr/bin/php8.0`).

This setup will ensure that `pppaymentstg.php` runs on a regular schedule and sends summaries to your Telegram bot as expected. Let me know if you need any further assistance or adjustments!
