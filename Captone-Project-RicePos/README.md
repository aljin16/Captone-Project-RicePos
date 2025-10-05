# RicePOS

A modern web-based Point of Sale (POS) system with admin and staff roles, inventory, and sales modules.

## Features
- Secure login with bcrypt password hashing
- Admin and staff user roles
- Dashboard, POS, Inventory, and User Management modules
- Modern responsive design (PHP, MySQL, JS, CSS)

## Installation
1. **Clone or copy the project to your web server directory.**
2. **Create a MySQL database named `ricepos`.**
3. **Import the database schema and initial admin user:**
   - Import `migrations/create_tables.sql` into your `ricepos` database (via phpMyAdmin or MySQL CLI).
4. **Configure database connection:**
   - Edit `includes/db.php` if your MySQL username/password is not `root`/blank.
5. **Access the app:**
   - Open `public/index.php` in your browser.

## Default Admin Credentials
- **Username:** `admin`
- **Password:** `admin123`

---------Guide----------------------------------------
first : import the database named: ricepos
database filename: ricepos.sql

1) Opening this system:  http://localhost/RicePos/Captone-Project-RicePos/public/

2) FOR Location sharing device use of Rider(Edgar)

-reminder to open and allowed location turn on in Device(Laptop or CP)
-Open this in another tab in Brave browser
http://localhost/public/RicePos/public/gps_beacon.php

-----------------------API Weather------------------------------

Api key for weather :726ba1a31076402797324112251008
Website: https://www.weatherapi.com/my/
Account use: aljeansinohin05@gmail.com

##iPV4 RESET PASSWORK Link via Lan change
change it in config.php in bottom line 
same wifi connected
--------------------------------------------------------

## Cursor Ai command prompt-Terminal (Run as administrator)
Windows: 
-irm https://raw.githubusercontent.com/yeongpin/cursor-free-vip/main/scripts/install.ps1 | iex

------------------------------------------------------------
> ⚠️ For security, change the admin password after first login.

## File Structure
- `public/` — Main web pages and assets
- `includes/` — PHP logic (DB, auth, helpers)
- `migrations/` — SQL for database setup
**Folder structure
xampp>htdocs>public
## Requirements
- PHP 7.2+
- MySQL 5.7+
- Web server (Apache, Nginx, XAMPP, etc.)

----Accounts------
##Admin account
Username: admin
Pass: admin123

Username: Mylean
Pass: admin

##Staff account
Username: staff
Pass: staff



Developed by: Aljean Sinohin 