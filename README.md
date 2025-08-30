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

## Requirements

* A web server with PHP (7.2+ recommended).
* PHP Extensions:
  * `pdo_sqlite` (for the database)
  * `curl` (for Proxmox integration and Discord notifications)
* Shell access to set up a cron job.

## Installation

1. **Clone the Repository:**
   Clone this repository to a folder on your web server.
