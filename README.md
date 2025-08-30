# FamPing - A PHP-based Host and Service Monitor

FamPing is a simple, self-hosted, PHP-based application for monitoring the status of IP addresses and hostnames. It features a clean web dashboard, parent-child dependency relationships to prevent notification floods, and powerful integration with Proxmox VE.

## Key Features

* **Simple Ping Monitoring:** Check the status of any IP address or hostname.
* **Parent-Child Dependencies:** Set relationships between monitors (e.g., a server behind a switch). If the parent goes down, notifications for children are suppressed.
* **Proxmox VE Integration:** Automatically import all VMs and LXC containers from your Proxmox server as child monitors.
* **At-a-Glance History:** See the last 30 checks for each monitor directly on the dashboard.
* **Flexible Notifications:** Receive alerts via SMTP Email and Discord Webhooks.
* **Web-Based Configuration:** All settings, including notifications and Proxmox servers, are managed through the GUI.
* **Lightweight & Portable:** Uses PHP and a simple SQLite database file. Now with Docker support for easy deployment.

---

## Docker Installation (Recommended)

This is the easiest way to get FamPing running. It uses Docker and Docker Compose to manage the web server and the background checking script automatically.

### Prerequisites

* [Docker](https://www.docker.com/get-started)
* [Docker Compose](https://docs.docker.com/compose/install/)

### Instructions

1.  **Clone the Repository:**
    Clone this repository, which includes the `Dockerfile` and `docker-compose.yml` files, to your machine.
    ```
    git clone <your-repository-url> famping
    cd famping
    ```

2.  **Build and Run the Containers:**
    From inside the `famping` directory, run the following command. This will build the PHP image, create a persistent volume for the database, and start the web and cron services in the background.
    ```
    docker-compose up --build -d
    ```

3.  **Run the Setup Script:**
    The first time you start the application, you must run the setup script. Open your web browser and navigate to:
    **[http://localhost:8080/setup.php](http://localhost:8080/setup.php)**

4.  **IMPORTANT: Delete Setup File:**
    After setup is complete, **delete the `setup.php` file** from your project folder for security. The container will see this change automatically.

5.  **Access FamPing:**
    You can now access your FamPing dashboard at **[http://localhost:8080](http://localhost:8080)**.

The cron job is handled by a separate service in `docker-compose.yml` and will start checking your monitors automatically.

---

## Manual Installation

Use these instructions if you want to install FamPing directly on a web server without using Docker.

### Requirements

* A web server (Apache, Nginx, etc.) with PHP (7.2+ recommended).
* PHP Extensions: `pdo_sqlite` and `curl`.
* Shell access to configure a cron job.

### Instructions

1.  **Deploy Files:**
    Clone or copy the application files to a folder on your web server.

2.  **Run the Setup Script:**
    Open your browser and navigate to `http://your-server.com/folder/setup.php` to create the database.

3.  **Delete Setup File:**
    After setup is complete, **delete the `setup.php` file** for security.

4.  **Set File Permissions:**
    Ensure your web server can write to the `monitor.db` file.
    ```
    chmod 664 /path/to/your/folder/monitor.db
    sudo chown www-data:www-data /path/to/your/folder/monitor.db
    ```
    *(The user `www-data` might be different on your system, e.g., `apache`)*

5.  **Configure the Cron Job:**
    Open your crontab editor (`crontab -e`) and add a line to run the check script every few minutes.
    ```
    */5 * * * * /usr/bin/php /path/to/your/folder/check_monitors.php
    ```

---

## Configuration & Usage

Once installed, all configuration is done through the web interface.

* **Monitors:** Use the "Add Monitor" tab to create new monitors.
* **Settings:** Configure your SMTP and Discord notification channels.
* **Proxmox:** Add your server credentials and sync VMs/LXCs automatically.

## License

This project is licensed under the MIT License.
