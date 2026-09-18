# Luprah ERP — Clean Reset & Fresh Setup

Follow these steps in order. Takes about 10 minutes.

## PART A — Delete the old broken files
1. Log in to **cPanel** → **File Manager**.
2. Go into the **`erp`** folder (inside `public_html`).
3. Select **all** files inside it → **Delete** (also empty the Trash).
   - This does NOT touch your public website (luprah.online homepage stays).

## PART B — Wipe the old database
1. cPanel → **phpMyAdmin**.
2. On the left, click your database **`lupruhrv_luprah_erp`**.
3. Click the **SQL** tab at the top.
4. Open **`reset.sql`** (from this download), copy ALL of it, paste into the box, click **Go**.
   - This removes all old tables. (If it says a table didn't exist, that's fine.)

## PART C — Upload the fresh app
1. Back in **File Manager**, open the **`erp`** folder.
2. Click **Upload**, choose **`mystore-erp.zip`**, wait for 100%.
3. Right-click the uploaded `mystore-erp.zip` → **Extract** → extract into the `erp` folder.
4. It creates a folder **`erp-app`**. Open it, **Select All** files, **Move** them up into `/erp/`
   so the files sit directly in `/erp/` (not `/erp/erp-app/`). Delete the empty `erp-app` folder and the zip.

## PART D — Enter your database password
1. In `/erp/`, right-click **`config.php`** → **Edit**.
2. The database name and user are already filled in. On the **DB_PASS** line,
   replace `CHANGE_ME` with your real MySQL password (between the quotes).
3. **Save**.
   - If you forgot the password: cPanel → **MySQL Databases** → under "Current Users",
     click the user → set a new password → also make sure the user is **Added to** the
     database with **ALL PRIVILEGES**.

## PART E — Install
1. In your browser go to: **https://luprah.online/erp/install.php**
2. Enter your name, a username, and a password (min 6 characters). Click **Install Now**.
3. When it says ✅ Installed — go back to File Manager and **DELETE `install.php`** (important).

## PART F — Log in
- Go to **https://luprah.online/erp/login.php** and sign in.
- Done! Fresh, clean system.

---
### If a page ever shows a 500 error
Upload **`check.php`** to `/erp/`, visit `https://luprah.online/erp/check.php`,
click **Repair**, then delete `check.php`. It fixes missing columns automatically.
