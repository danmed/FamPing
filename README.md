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

## Screenshots

<img width="1960" height="957" alt="image" src="https://github.com/user-attachments/assets/ee341ff7-229c-4278-875f-35be34846a5e" />

## Installation

There are two ways to install FamPing: using Docker (recommended for ease of use and portability) or a traditional manual setup.

---

### Docker Installation (Recommended)

This method uses Docker and Docker Compose to run the application in an isolated environment, handling all dependencies automatically.

**Requirements:**
* Docker
* Docker Compose

**Steps:**

1.  **Clone the Repository:**
    Clone this repository to a folder on your machine.
    ```bash
    git clone https://github.com/danmed/FamPing/ famping
    cd famping
    ```

2.  **IMPORTANT - Reset Old Environment (If Applicable):**
    If you were using a previous, broken Docker setup, you **must** clean it up first. Run these commands from inside the `famping` folder to remove the old container and the problematic local database file:
    ```bash
    docker compose down
    sudo rm -f ./monitor.db
    ```

3.  **Build and Run the Container:**
    Use Docker Compose to build the image and start the container in the background.
    ```bash
    docker compose up --build -d
    ```

4.  **Run the Setup Script:**
    Open your web browser and navigate to `http://localhost:8080/setup.php`. This will create and initialize the `monitor.db` database inside its own persistent Docker volume.

5.  **IMPORTANT: Delete Setup File:**
    After you see the "Setup Complete!" message, **you must delete the `setup.php` file** from your project folder for security. The application is now ready to use.
    ```bash
    rm setup.php
    ```

You can now access the FamPing dashboard at `http://localhost:8080`.

---

### Manual Installation

This method is for a traditional web server environment (e.g., Apache or Nginx with PHP).

**Requirements:**
* A web server with PHP (7.2+ recommended).
* PHP Extensions: `pdo_sqlite` and `curl`.
* Shell access to set up a cron job.

**Steps:**

1.  **Upload Files:**
    Upload all the `.php` files to a folder on your web server.

2.  **Fix for Non-Docker Use:**
    Before running the setup, you **must** edit `setup.php` and `config.php`. Change the line `$db_path = '/data/monitor.db';` to `$db_path = __DIR__ . '/monitor.db';` in both files.

3.  **Set Permissions:**
    Ensure your web server has permission to create and write to files in the application directory.

4.  **Run Setup:**
    Navigate to `http://your-server.com/folder/setup.php` in your browser to create the database.

5.  **Delete Setup File:**
    For security, delete `setup.php` after setup is complete.

6.  **Configure Cron Job:**
    Set up a cron job to run `check_monitors.php` every few minutes.
    ```bash
    # Open crontab editor
    crontab -e
    
    # Add this line (adjust paths and schedule)
    */5 * * * * /usr/bin/php /path/to/your/folder/check_monitors.php
    ```

## Configuration & Usage

Once installed, all configuration is done through the web interface.

* **Monitors:** Use the "Add Monitor" tab to create new monitors.
* **Settings:** Go to the "Settings" tab to configure your SMTP and Discord notifications.
* **Proxmox:** Use the "Proxmox" tab to add your server credentials and sync your VMs/LXCs.

## License

This project is licensed under the MIT License.
