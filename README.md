/*

# FamPing - A PHP-based Host and Service Monitor

FamPing is a simple, self-hosted, PHP-based application for monitoring the status of IP addresses and hostnames. It features a clean web dashboard, parent-child dependency relationships to prevent notification floods, and powerful integration with Proxmox VE.

## Key Features

* **Simple Ping Monitoring:** Check the status of any IP address or hostname.
* **Parent-Child Dependencies:** The core feature. If a parent (like a switch) goes down, notifications for its children are automatically suppressed.
* **Proxmox VE Integration:** Automatically import all your VMs and LXC containers as child monitors of their host.
* **At-a-Glance History:** See the last 30 checks for each monitor directly on the dashboard.
* **Flexible Notifications:** Receive alerts via SMTP Email and Discord Webhooks.
* **Web-Based Configuration:** All settings are managed through the GUI.
* **Easy Docker Deployment:** Get up and running in minutes with Docker and Docker Compose.

---

## Screenshots

<img width="1960" height="957" alt="image" src="https://github.com/user-attachments/assets/ee341ff7-229c-4278-875f-35be34846a5e" />

## Docker Installation (Recommended)

This is the easiest and most reliable way to run FamPing.

### Prerequisites

* [Docker](https://www.docker.com/get-started)
* [Docker Compose](https://docs.docker.com/compose/install/)

### Instructions

1.  **Clone the Repository:**
    Get the code, which includes the `Dockerfile` and `docker-compose.yml` files.
    ```
    git clone <your-repository-url> famping
    cd famping
    ```

2.  **Build and Run:**
    From inside the `famping` directory, run the following command. This builds the image and starts the web server and the background checking script.
    ```
    docker-compose up --build -d
    ```

3.  **Run the Setup Script:**
    The very first time you start the app, you must run the setup script. Open your browser to:
    **[http://localhost:8080/setup.php](http://localhost:8080/setup.php)**

4.  **IMPORTANT: Delete Setup File:**
    After setup is complete, **delete the `setup.php` file** from your project folder for security.

5.  **Access FamPing:**
    You can now access your FamPing dashboard at **[http://localhost:8080](http://localhost:8080)**. The database file (`monitor.db`) will be created and stored directly in your project folder.

---

## Manual Installation

Use these instructions if you want to install FamPing directly on a web server without Docker.

### Requirements

* A web server (Apache, Nginx) with PHP (7.2+ recommended).
* PHP Extensions: `pdo_sqlite` and `curl`.
* Shell access to configure a cron job.

### Instructions

1.  **Deploy Files:** Copy the application files to a folder on your web server.
2.  **Run Setup:** Navigate to `http://your-server.com/folder/setup.php` in your browser.
3.  **Delete Setup File:** Delete `setup.php` after it completes.
4.  **Set Permissions:** Ensure your web server can write to the `monitor.db` file (e.g., `sudo chown www-data:www-data monitor.db`).
5.  **Configure Cron Job:** Open your crontab (`crontab -e`) and add a line to run the check script: `*/5 * * * * /usr/bin/php /path/to/your/folder/check_monitors.php`.

---

## Configuration & Usage

Once installed, all configuration is done through the web interface.

* **Monitors:** Add monitors and set parent-child relationships.
* **Settings:** Configure SMTP and Discord notifications.
* **Proxmox:** Add your server credentials and sync VMs/LXCs.

## License

This project is licensed under the MIT License.

*/
