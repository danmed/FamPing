# FamPing - A PHP-based Host and Service Monitor

FamPing is a simple, self-hosted, PHP-based application for monitoring the status of IP addresses and hostnames. It features a clean web dashboard, parent-child dependency relationships to prevent notification floods, and powerful integration with Proxmox VE.

## Key Features

* **Simple Ping Monitoring:** Check the status of any IP address or hostname.

* **Parent-Child Dependencies:** Set relationships between monitors (e.g., a server behind a switch). If the parent goes down, notifications for children are suppressed.

* **Proxmox VE Integration:** Automatically import all VMs and LXC containers from your Proxmox server as child monitors. The script intelligently discovers guest IP addresses.

* **At-a-Glance History:** See the last 30 checks for each monitor directly on the dashboard.

* **Detailed History:** View a detailed log of status changes for each monitor.

* **Flexible Notifications:** Receive alerts via SMTP Email and Discord Webhooks.

* **Web-Based Configuration:** All settings, including notifications and Proxmox servers, are managed through the GUI. No need to edit config files after setup.

* **Lightweight:** Uses PHP and a simple SQLite database file, requiring no complex setup.

## Scrennshots

<img width="2014" height="1023" alt="image" src="https://github.com/user-attachments/assets/de0d345c-d9b9-4a39-8832-2da2e1e54a54" />


## Requirements

* A web server with PHP (7.2+ recommended).

* PHP Extensions:

  * `pdo_sqlite` (for the database)

  * `curl` (for Proxmox integration and Discord notifications)

* Shell access to set up a cron job.

## Installation

1. **Clone the Repository:**
   Clone this repository to a folder on your web server.

   ```
   git clone <your-repository-url> /path/to/your/folder
   
   ```

2. **Run the Setup Script:**
   Open your web browser and navigate to `http://your-server.com/folder/setup.php`. This will create the `monitor.db` SQLite database file in the same directory.

3. **IMPORTANT: Delete Setup File:**
   After you see the "Setup Complete!" message, **you must delete the `setup.php` file** from your server for security.

4. **Set File Permissions:**
   Ensure your web server has permission to write to the `monitor.db` file.

   ```
   chmod 664 /path/to/your/folder/monitor.db
   sudo chown www-data:www-data /path/to/your/folder/monitor.db
   
   ```

   *(The user/group `www-data` might be different on your system, e.g., `apache`, `nginx`)*

5. **Configure the Cron Job:**
   The `check_monitors.php` script needs to be run on a regular schedule to perform the pings. Set up a cron job to run every few minutes.

   Open your crontab editor:

   ```
   crontab -e
   
   ```

   Add the following line to run the check every 5 minutes (adjust the schedule and paths as needed):

   ```
   */5 * * * * /usr/bin/php /path/to/your/folder/check_monitors.php
   
   ```

## Configuration & Usage

Once installed, all configuration is done through the web interface at `http://your-server.com/folder/`.

* **Monitors:** Use the "Add Monitor" tab to create new monitors. You can assign parents to create dependencies.

* **Settings:** Go to the "Settings" tab to configure your SMTP email and Discord webhook notification channels.

* **Proxmox:** Use the "Proxmox" tab to add your server credentials. Once added, you can click "Sync" to automatically import all your VMs and LXCs as child monitors.

## License

This project is licensed under the MIT License. See the `LICENSE` file for details
