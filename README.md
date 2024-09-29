# pppayments
A script




# Installation Instructions for `pppaymentswh.php`

Follow the steps below to set up and deploy the `pppaymentswh.php` PayPal webhook script on your server using Composer.

### Step 1: Clone the GitHub Repository

1. **SSH into your web server** (e.g., Ubuntu 22.04) where you want to deploy the webhook script.
2. **Navigate to your desired directory** (e.g., `/var/www/html/`):

   ```bash
   cd /var/www/html/
   ```

3. **Clone the GitHub repository** to get the latest code:

   ```bash
   git clone https://github.com/drhdev/pppayments.git
   ```

   This command will create a directory called `pppayments` with your project files.

4. **Navigate to the project directory**:

   ```bash
   cd pppayments
   ```

### Step 2: Install Dependencies with Composer

1. **Run Composer to Install Dependencies**:

   ```bash
   composer install
   ```

   This will read the `composer.json` file, download all required packages (`vlucas/phpdotenv` and `monolog`), and set up the `vendor` directory.

### Step 3: Configure the Environment Variables

1. After installing, you should have a `.env` file (if not, manually create it based on `.env.example`):

   ```bash
   cp .env.example .env
   ```

2. **Edit the `.env` file** to match your database and server configuration:

   ```bash
   nano .env
   ```

   Update the fields according to your environment:

   ```
   DB_HOST=127.0.0.1
   DB_NAME=your_database_name
   DB_USER=your_username
   DB_PASS=your_password
   ```

   Save the file and exit (`Ctrl + X`, then `Y` and `Enter`).

### Step 4: Configure Apache (Optional)

If you want the script to be accessible as a public webhook endpoint:

1. **Create a virtual host** or configure your existing Apache configuration to point to the directory where the script is located:

   ```bash
   sudo nano /etc/apache2/sites-available/pppayments.conf
   ```

   Add the following configuration:

   ```apache
   <VirtualHost *:80>
       ServerAdmin webmaster@localhost
       DocumentRoot /var/www/html/pppayments
       ServerName your-domain.com

       <Directory /var/www/html/pppayments>
           AllowOverride All
           Require all granted
       </Directory>

       ErrorLog ${APACHE_LOG_DIR}/pppayments_error.log
       CustomLog ${APACHE_LOG_DIR}/pppayments_access.log combined
   </VirtualHost>
   ```

2. **Enable the new site** and reload Apache:

   ```bash
   sudo a2ensite pppayments.conf
   sudo systemctl reload apache2
   ```

### Step 5: Test the Setup

1. **Navigate to the script’s directory** and check if everything is working:

   ```bash
   php pppaymentswh.php
   ```

   If there are any errors, they will be logged to your defined log file (`/var/log/apache2/pppaymentswh.log`).

2. **Send a test webhook** from PayPal to verify that the script processes the data correctly.

### Step 6: Secure Your Deployment

1. **Restrict Access to the `.env` File**:

   Set permissions to the `.env` file so only the web server can read it:

   ```bash
   sudo chmod 600 .env
   sudo chown www-data:www-data .env
   ```

2. **Hide Sensitive Files**:

   To prevent direct access to `.env` or other sensitive files through the web server, add the following lines to your `.htaccess` file in the project directory:

   ```apache
   <Files .env>
       Order allow,deny
       Deny from all
   </Files>
   ```

### Step 7: Configure Logging

Ensure that the log file location specified in `pppaymentswh.php` is writable by the web server:

```php
define('LOG_FILE', '/var/log/apache2/pppaymentswh.log');
```

You may need to create the log directory if it does not exist:

```bash
sudo mkdir -p /var/log/apache2
sudo touch /var/log/apache2/pppaymentswh.log
sudo chown www-data:www-data /var/log/apache2/pppaymentswh.log
```

### Final Setup

Your script is now deployed using Composer with all dependencies managed via the `composer.json` file. This setup ensures that you can easily update dependencies, share your project, and securely handle configurations.
