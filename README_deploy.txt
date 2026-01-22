# Tic-Tac-Toe Tournament - cPanel Deployment Guide

## Prerequisites
- cPanel hosting account with PHP 8.0+ and MySQL 5.7+
- SSH access to server (optional but recommended)
- Domain/subdomain pointed to your cPanel account
- Kukupay merchant account (for payments)
- Telegram Bot Token (from @BotFather)

## Step 1: Upload Files to cPanel

### Using File Manager:
1. Log into your cPanel account
2. Go to "Files" → "File Manager"
3. Navigate to `public_html` (or your subdomain directory)
4. Click "Upload" and upload all project files
5. Extract the ZIP if uploaded as archive

### Using FTP:
1. Use FTP client (FileZilla, WinSCP, etc.)
2. Connect to your cPanel FTP details
3. Upload all files to `public_html` directory
4. Ensure correct permissions (755 for directories, 644 for files)

## Step 2: Create MySQL Database

1. In cPanel, go to "Databases" → "MySQL Databases"
2. Create new database: `tic_tac_toe_tournament`
3. Create database user with strong password
4. Add user to database with ALL privileges
5. Note down: database name, username, password

## Step 3: Import Database Schema

### Using phpMyAdmin:
1. In cPanel, go to "Databases" → "phpMyAdmin"
2. Select your database from left sidebar
3. Click "Import" tab
4. Upload `database.sql` file
5. Click "Go" to execute

### Using MySQL command line (if SSH available):
```bash
mysql -u db_username -p db_name < database.sql
```

## Step 4: Configure Environment Variables

### Create .env file:
1. In File Manager, create `.env` file in project root
2. Add the following content (replace with your actual values):

```
# Database Configuration
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

# Application Settings
APP_ENV=production
APP_URL=https://yourdomain.com
TIMEZONE=Asia/Kolkata

# Telegram Bot
TELEGRAM_BOT_TOKEN=your_bot_token_here

# Kukupay Payment Gateway
KUKUPAY_API_KEY=your_kukupay_api_key

# Admin Settings
ADMIN_EMAIL=admin@yourdomain.com

# UPI IDs (comma-separated)
UPI_IDS=admin@paytm,admin@phonepe,admin@gpay
```

### Alternative: Use cPanel Environment Variables
1. Go to "Software" → "MultiPHP INI Editor"
2. Select your domain
3. Add the following under [PHP] section:

```ini
; Database
env[DB_HOST]=localhost
env[DB_NAME]=your_database_name
env[DB_USER]=your_database_user
env[DB_PASS]=your_database_password

; Application
env[APP_ENV]=production
env[APP_URL]=https://yourdomain.com
env[TIMEZONE]=Asia/Kolkata

; Telegram
env[TELEGRAM_BOT_TOKEN]=your_bot_token_here

; Kukupay
env[KUKUPAY_API_KEY]=your_kukupay_api_key

; Admin
env[ADMIN_EMAIL]=admin@yourdomain.com

; UPI
env[UPI_IDS]=admin@paytm,admin@phonepe,admin@gpay
```

## Step 5: Set Up Directory Permissions

### Using File Manager:
1. Right-click on these directories and select "Change Permissions":
   - `uploads/` → 755
   - `config/` → 755
   - `api/` → 755
   - `admin/` → 755
   - `webapp/` → 755

### Using SSH:
```bash
chmod 755 uploads config api admin webapp
chmod 644 config/config.php
```

## Step 6: Configure PHP Settings

1. In cPanel, go to "Software" → "MultiPHP Manager"
2. Select your domain
3. Set PHP Version to 8.0 or higher
4. Click "Apply"

### PHP Configuration:
1. Go to "Software" → "MultiPHP INI Editor"
2. Select your domain
3. Set the following:

```ini
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
post_max_size = 20M
upload_max_filesize = 10M
max_file_uploads = 10
```

## Step 7: Set Up CRON Jobs

### For Tournament Processing:
1. In cPanel, go to "Advanced" → "Cron Jobs"
2. Add new cron job:
   - Common Settings: "Every 5 minutes"
   - Command: `php /home/username/public_html/cron/process_tournaments.php`

### For Cleanup Tasks:
1. Add another cron job:
   - Common Settings: "Once a day" (choose appropriate time)
   - Command: `php /home/username/public_html/cron/cleanup.php`

*Note: Replace `username` with your cPanel username*

## Step 8: Configure Telegram Bot Webhook

### Set Webhook URL:
1. Your webhook URL should be: `https://yourdomain.com/bot/webhook.php`
2. Use curl to set webhook (run on your local machine):

```bash
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setWebhook" \
     -d "url=https://yourdomain.com/bot/webhook.php"
```

### Register Bot Commands:
```bash
curl -X POST "https://api.telegram.org/botYOUR_BOT_TOKEN/setMyCommands" \
     -H "Content-Type: application/json" \
     -d '{
       "commands": [
         {"command": "start", "description": "Start the bot"},
         {"command": "play", "description": "Open Tic-Tac-Toe game"},
         {"command": "wallet", "description": "Check wallet balance"},
         {"command": "tournaments", "description": "View tournaments"},
         {"command": "help", "description": "Get help"}
       ]
     }'
```

## Step 9: Configure Kukupay Webhook

1. In your Kukupay merchant dashboard, set webhook URL to:
   `https://yourdomain.com/api/kukupay_webhook.php`
2. Ensure webhook secret matches your KUKUPAY_API_KEY

## Step 10: Test Installation

### Test Database Connection:
Visit: `https://yourdomain.com/api/test_db.php`

### Test Admin Login:
1. Visit: `https://yourdomain.com/admin/`
2. Default admin credentials:
   - Username: `admin`
   - Password: `admin123`

### Test WebApp:
1. Open Telegram
2. Start your bot with `/start`
3. Click the WebApp button to open the game

### Test Payments:
1. Try adding money via Kukupay (use test credentials if available)
2. Test manual UPI payment upload

## Step 11: Security Hardening

### Change Admin Password:
1. Log into admin panel
2. Go to profile/settings
3. Change default password immediately

### SSL Certificate:
1. In cPanel, go to "Security" → "SSL/TLS"
2. Install Let's Encrypt SSL certificate for your domain

### Backup Configuration:
1. Go to "Files" → "Backup"
2. Set up automated backups for files and database

### Firewall Rules:
1. Go to "Security" → "ModSecurity"
2. Configure basic security rules

## Step 12: Monitoring and Maintenance

### Error Logs:
- Check `/home/username/public_html/error_log`
- PHP errors are logged here

### Database Backups:
- Set up automated database backups in cPanel
- Store backups off-server if possible

### Performance Monitoring:
- Use cPanel's "Metrics" section to monitor resource usage
- Optimize images and minify CSS/JS for better performance

## Troubleshooting

### Common Issues:

1. **500 Internal Server Error:**
   - Check file permissions
   - Verify PHP version
   - Check .env file syntax

2. **Database Connection Failed:**
   - Verify database credentials in .env
   - Check if database exists
   - Ensure user has proper privileges

3. **Webhook Not Working:**
   - Verify webhook URL is accessible
   - Check SSL certificate
   - Review webhook logs

4. **File Upload Issues:**
   - Check uploads directory permissions
   - Verify PHP upload settings
   - Check disk space

### Support:
- Check error logs in cPanel → "Metrics" → "Errors"
- Use "phpinfo.php" to verify PHP configuration
- Contact hosting support for server-related issues

## Post-Deployment Checklist

- [ ] Files uploaded successfully
- [ ] Database created and imported
- [ ] Environment variables configured
- [ ] Permissions set correctly
- [ ] PHP version and settings configured
- [ ] CRON jobs set up
- [ ] Telegram webhook configured
- [ ] Kukupay webhook configured
- [ ] SSL certificate installed
- [ ] Admin password changed
- [ ] Basic functionality tested
- [ ] Backups configured

## Production Notes

- Monitor server resources regularly
- Keep PHP and MySQL updated
- Regularly backup database and files
- Monitor payment transactions
- Keep Kukupay and Telegram integrations updated
- Implement rate limiting for API endpoints if needed

---

*This deployment guide is comprehensive. Follow each step carefully. If you encounter issues, check the troubleshooting section first.*