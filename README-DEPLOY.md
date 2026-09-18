# MyStore ERP — Installation Guide (cPanel + MySQL)

A complete PHP + MySQL order & business management system. No frameworks,
no Composer — runs on any standard cPanel shared hosting with PHP 7.4+ / 8.x.

## What you need
- cPanel hosting with **File Manager** and **MySQL Databases** (almost all hosts).
- 10 minutes.

---

## STEP 1 — Create the database (cPanel → "MySQL Databases")
1. Under **Create New Database**, type a name e.g. `erp` → **Create Database**.
   cPanel will name it like `youruser_erp` — note the full name.
2. Under **MySQL Users → Add New User**, create a user e.g. `erp` with a strong
   password → **Create User**. It becomes `youruser_erp`. Note user + password.
3. Under **Add User To Database**, pick the user + database → **Add** →
   tick **ALL PRIVILEGES** → **Make Changes**.

## STEP 2 — Upload the files
1. cPanel → **File Manager** → open **public_html**
   (or a subfolder like `public_html/erp` if you want it at yourdomain.com/erp).
2. Click **Upload** and upload **mystore-erp.zip**.
3. Back in File Manager, right-click the zip → **Extract**. You'll see
   `index.php`, `config.php`, `database.sql`, the `includes/` & `assets/` folders, etc.

## STEP 3 — Enter your database details
1. In File Manager, right-click **config.php** → **Edit**.
2. Change only these 4 lines to match STEP 1, then **Save**:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'youruser_erp');   // full database name
   define('DB_USER', 'youruser_erp');   // full user name
   define('DB_PASS', 'your_password');  // the user's password
   ```

## STEP 4 — Run the installer (creates all tables + your admin login)
1. Visit **https://yourdomain.com/install.php**
   (or **/erp/install.php** if you used a subfolder).
2. Enter your name, an admin username, and a password (min 6 chars) → **Install Now**.
3. When it says "Installed!", go to File Manager and **DELETE install.php**
   (important for security).

> Alternative to the installer: in cPanel → **phpMyAdmin**, select your database,
> open the **Import** tab, choose **database.sql**, and **Go**. Then you still need
> one admin user — easiest is to just use install.php once and delete it.

## STEP 5 — Log in and set up
1. Visit **https://yourdomain.com/** (or /erp/) → log in with your admin account.
2. Open **Settings** and set: store name, currency, delivery charges
   (inside/outside valley), opening cash & bank balances, USD rate.
3. Add your **Products**, **Couriers**, **Customers**, then start taking **Orders**
   in the Sales page. The Dashboard, Reports and Courier Center fill in automatically.

---

## Pages included
Dashboard, Users, Products, Inventory, Sales (orders), Purchases, Customers,
Suppliers, Couriers (Courier Center), Accounting, Expenses, HRM, Reports,
Settings, Activity Logs, Login/Logout, Installer.

## Key business rules built in
- **Revenue = product sales only** (sell × qty) on **delivered** orders.
  Delivery charges are tracked **separately**, never counted as revenue.
- Profit realised on delivery = (sell − cost) × qty; cancelled/returned = −cancel fee.
- **Courier records lock** once created (no edit/delete) like finalised transactions.
- Expenses support **product-wise Ads** and **USD entries** (auto-converted to Rs,
  description required).
- Delivery auto-fills Rs 80 inside / Rs 150 outside the valley (editable in Settings).

## Security tips
- Delete **install.php** after installing.
- Use a strong admin password; add staff under **Users** with limited roles.
- Keep `config.php` private (it holds your DB password).

## Notes
- Charts use Chart.js loaded from a public CDN (needs internet in the browser —
  works on any host). Everything else is self-hosted PHP.
- Money is stored as exact DECIMAL in MySQL.
