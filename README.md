# PHPing - A PHP-based Host and Service Monitor

PHPing is a simple, self-hosted, PHP-based application for monitoring the status of IP addresses and hostnames. It features a clean web dashboard, parent-child dependency relationships to prevent notification floods, and powerful integration with Proxmox VE.

## Key Features

* **Simple Ping Monitoring:** Check the status of any IP address or hostname.
* **Parent-Child Dependencies:** Set relationships between monitors (e.g., a server behind a switch). If the parent goes down, notifications for children are suppressed.
* **Proxmox VE Integration:** Automatically import all VMs and LXC containers from your Proxmox server as child monitors. The script intelligently discovers guest IP addresses.
* **Live Dashboard:** The dashboard auto-refreshes every 30 seconds, providing live status updates without a full page reload.
* **At-a-Glance History:** See the last 30 checks for each monitor directly on the dashboard.
* **Detailed History:** View a detailed log of status changes for each monitor.
* **Flexible Notifications:** Receive alerts via SMTP Email and Discord Webhooks.
* **Web-Based Configuration:** All settings, including notifications and Proxmox servers, are managed through the GUI.
* **Lightweight:** Uses PHP and a simple SQLite database file, requiring no complex setup.
* **Docker Support:** Comes with a pre-configured Docker setup for easy, portable deployment.

## Screenshots

<img width="1960" height="957" alt="image" src="https://github.com/user-attachments/assets/ee341ff7-229c-4278-875f-35be34846a5e" />

## Installation Methods

You can install PHPing using Docker (recommended for portability) or a traditional web server setup.

### Docker Installation (Recommended)

This method uses Docker and Docker Compose to run the application in an isolated environment.

**Requirements:**
* Docker
* Docker Compose

**Steps:**

1.  **Clone the Repository:**
    ```bash
    git clone https://github.com/danmed/PHPing phpping
    cd phpping
    ```

2.  **Build and Start the Container:**
    Run the following command. This will build the Docker image and start the container in the background.
    ```bash
    docker-compose up --build -d
    ```

3.  **Access the Setup Script:**
    Open your web browser and navigate to `http://localhost:8080/setup.php`. This will create and prepare the `monitor.db` database inside the container's persistent volume.

4.  **IMPORTANT: Delete Setup File:**
    After you see the "Setup Complete!" message, **you must delete the `setup.php` file** from your local project folder for security.
    ```bash
    rm setup.php
    ```

5.  **Done!**
    You can now access the PHPing dashboard at `http://localhost:8080`. The background checking script is already running automatically inside the container.

### Traditional Web Server Installation

**Requirements:**
* A web server (Apache, Nginx, etc.) with PHP (7.4+ recommended).
* PHP Extensions: `pdo_sqlite`, `curl`.
* Shell access to configure a cron job.

**Steps:**

1.  **Prepare the Code:**
    Clone or download the repository to a folder on your web server. You will need to edit `config.php` and `setup.php` and change the line `$db_path = '/data/monitor.db';` to `$db_path = __DIR__ . '/monitor.db';` to make it work in a non-Docker environment.

2.  **Run the Setup Script:**
    Navigate to `http://your-server.com/path/to/setup.php`. This creates the `monitor.db` file.

3.  **IMPORTANT: Delete Setup File:**
    After setup is complete, **delete `setup.php`** for security.

4.  **Set Permissions:**
    Ensure your web server has permission to write to the database file.
    ```bash
    chmod 664 /path/to/your/folder/monitor.db
    sudo chown www-data:www-data /path/to/your/folder/monitor.db
    ```
    *(The user/group `www-data` might be different on your system, e.g., `apache`)*

5.  **Configure the Cron Job:**
    Set up a cron job to run `check_monitors.php` every few minutes.
    ```bash
    # Open crontab editor
    crontab -e
    
    # Add this line (runs every minute)
    * * * * * /usr/bin/php /path/to/your/folder/check_monitors.php
    
**Configuration & Usage**

Once installed, all configuration is done through the web interface.

* Monitors: Use the "Add Monitor" tab to create new monitors. You can assign parents to create dependencies.
* Settings: Go to the "Settings" tab to configure your SMTP email and Discord webhook notification channels.
* Proxmox: Use the "Proxmox" tab to add your server credentials. Once added, you can click "Sync" to automatically import all your VMs and LXCs as child monitors.

**License**

This project is licensed under the MIT License.
