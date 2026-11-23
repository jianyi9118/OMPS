# Installing OMPS (XAMPP 7.2.3, Windows)

[中文版](INSTALL.md)

For the colleague who looks after the server. You don't need internet access at any point, and you don't need to install any extra software or PHP extensions.

The menu names below are the ones shown when OMPS is set to English.

## 1. Preparation

1. Check the versions. Open the XAMPP Control Panel and click Shell.
   - Run `php -v`. It should show PHP 7.2 or later.
   - Run `mysql --version`. It should show MariaDB 10.1 or later.
2. Copy the `OMPS` folder to the server, for example to `D:\OMPS`. Do **not** put it inside `htdocs`.
3. In the XAMPP Control Panel, start Apache and MySQL.

## 2. Create the database and two accounts

In the Shell, run `mysql -u root`. Add `-p` if root has a password. Then run the SQL below, after replacing both passwords with strong passwords of your own:

```sql
CREATE DATABASE omps CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- everyday account: can read and write data, cannot change tables
CREATE USER 'omps_app'@'localhost' IDENTIFIED BY 'strong-password-1';
CREATE USER 'omps_app'@'127.0.0.1' IDENTIFIED BY 'strong-password-1';
GRANT SELECT, INSERT, UPDATE, DELETE ON omps.* TO 'omps_app'@'localhost', 'omps_app'@'127.0.0.1';
-- upgrade account: used only when running php omps migrate
CREATE USER 'omps_migrate'@'localhost' IDENTIFIED BY 'strong-password-2';
CREATE USER 'omps_migrate'@'127.0.0.1' IDENTIFIED BY 'strong-password-2';
GRANT ALL PRIVILEGES ON omps.* TO 'omps_migrate'@'localhost', 'omps_migrate'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## 3. Configuration

1. Copy `.env.example` to `.env`. Fill in `DB_PASSWORD` (strong password 1) and `DB_MIGRATE_PASSWORD` (strong password 2).
2. On the live server, keep `APP_ENV=production` and `APP_DEBUG=false`.
3. Set `FILES_PATH` to the folder where attachments are stored, outside the web folder. For example: `FILES_PATH=D:/OMPS/files`. If it is not set, attachments go to `storage/files`.
4. If the address has a sub-path, such as `https://server/omps`, set `APP_BASE_PATH=/omps`.

## 4. Tables, base data and the super administrator

In the Shell, go to the OMPS folder (for example `cd /d D:\OMPS`) and run these commands in order:

```
php omps key:generate         creates the encryption key APP_KEY (used to encrypt ID numbers and family phone numbers and addresses)
php omps env:check            checks the environment; fix every [FAIL] first
php omps migrate --seed       creates the tables and writes roles, permissions, menus, languages and other base data
php omps admin:create         creates the first super administrator (the password is typed in and not shown)
```

OMPS has no default accounts and no default passwords.

**Important: back up `.env`.** If `APP_KEY` is lost, encrypted ID numbers and family contact details can no longer be read. Every time you back up the database, back up `.env` and the attachments folder as well.

## 5. Apache

Apache must serve only the `public` folder. To set this up:

1. Add this line at the end of `xampp\apache\conf\httpd.conf`:
   `Include "conf/extra/omps.conf"`
2. Create `xampp\apache\conf\extra\omps.conf` with this content:

```apache
Alias /omps "D:/OMPS/public"
<Directory "D:/OMPS/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Restart Apache, then open `http://server/omps/` in a browser and sign in.

If mod_rewrite is not available, OMPS switches to `index.php?r=` addresses on its own. Everything still works.

## 6. Upload size, reminders and daily cleanup

- **Upload size.** The php.ini that comes with XAMPP allows uploads of only 2 MB. OMPS raises the limit to 50 MB in its own `public/.htaccess`. The change applies only to OMPS, so you don't need to edit php.ini. To change the limit, change both `.htaccess` and `UPLOAD_MAX_MB` in `.env`.
- **Reminders (every 5 minutes).** Follow-up reminders, reminders to write results and reminders about items waiting for review are all sent by `php omps schedule:run`. Open a Command Prompt as administrator and run the following once (adjust the paths to match your server):

  ```
  schtasks /create /tn "OMPS Reminders" /sc minute /mo 5 /ru SYSTEM /tr "D:\xampp\php\php.exe D:\OMPS\omps schedule:run"
  ```

  How reminders behave:
  - They are sent only during working hours. The default is Monday–Friday, 08:30–17:30; change it under **Settings → Work time (reminders)**.
  - Each reminder is sent only once, so it does no harm if the task runs more often.
  - If the task has not been created, any open OMPS page also runs reminders every 5 minutes.
  - `php omps env:check` tells you whether the task is running.
- **Daily cleanup.** Use Windows Task Scheduler to run `php D:\OMPS\omps cleanup` once a day. It does three things:
  - deletes expired temporary files;
  - permanently deletes files that have been in the recycle bin for more than 30 days;
  - deletes read notifications older than 180 days.

  ```
  schtasks /create /tn "OMPS Cleanup" /sc daily /st 02:00 /ru SYSTEM /tr "D:\xampp\php\php.exe D:\OMPS\omps cleanup"
  ```

## 6.1 Device collector client (optional)

Use this when you need to check computer hardware (design document §14). The client is a readable PowerShell script. The PowerShell built into Windows 7 and later is enough to run it, with nothing else to install.

1. The device administrator opens **Usage & monitoring → Collection scope** and confirms what is collected. By default that is computer name, operating system, CPU, memory, disks, motherboard, monitors and network adapters.
2. Under **Usage & monitoring → Collector clients**, download the 4 files: `install.cmd`, `omps-collector.ps1`, `uninstall.cmd` and `readme.txt`.
3. Open the computer in **Device records**. On its **Collector** tab, click **Create registration code**. The code is valid for 7 days and can be used once.
4. Copy the 4 files to that computer. Right-click `install.cmd` and choose "Run as administrator". Enter the server address (for example `http://10.0.0.5/omps`) and the registration code.

How the client reports:
- It reports 5 minutes after the computer starts, and again every day at 10:00.
- Hardware from the first report is registered as the device's parts automatically.
- After that, any difference from the registered parts creates an entry in **Anomalies**, and the device administrator and the unit head are notified.

What it does not collect: keystrokes, the screen, files, browsing history or sign-in accounts. Run `omps-collector.ps1 -Show` to see everything the computer would report.

## 6.2 Backups

**Where backups go.** Backups are written to `BACKUP_PATH` in `.env` (the default is `storage/backups`). On the live server it is best to put them on another disk or a NAS, for example `BACKUP_PATH=E:/OMPS-backup`.

**What a backup contains.** Each backup is two items with the same name:
- `omps_<date>_<time>_<type>.zip` holds the database and can be downloaded from the page;
- a folder of the same name holds the uploaded files.

Files that have not changed are shared with earlier backups and take no extra space; this needs an NTFS drive. When copying a backup, always copy both items together.

**Automatic backups.** Each time the "OMPS Reminders" task runs, it also checks whether a backup is due. A backup is made once a day at the set time: 02:00 by default, which you can change under **System → Backups**.
- Automatic backups are kept for 14 days by default.
- Manual backups are kept until deleted.

**Backup before upgrades.** When there is a new upgrade, `php omps migrate` makes a backup before changing any tables. If the backup fails, the upgrade does not run. To skip the backup when you really need to, add `--no-backup`.

**Encryption key.** Encrypted data such as ID numbers can be read only with the `APP_KEY` in `.env`.
- Keep a separate copy of `.env`, and don't store it in the same place as the backups.
- A backup made with a different APP_KEY cannot be restored.

**Restoring.** On the **Backups** page, click **Restore** and confirm with your own sign-in password. You can also run `php omps backup:restore <backup-file>.zip` on the command line.
- Before restoring, OMPS automatically backs up the current data.
- After a restore, everyone has to sign in again.
- A restore uses the upgrade account from `.env` (`DB_MIGRATE_USERNAME`).

**Moving to another server.**
1. Copy the zip and its folder together into the backup folder on the new server.
2. On the **Backups** page the backup appears as "Copied in". Click **Restore**.

The new server's `.env` must use the same APP_KEY.

## 7. Hardening before going live (design document §03)

- Allow HTTPS (port 443) only, with the unit's internal certificate, and set `SESSION_SECURE=true` in `.env`.
- MariaDB:
  - make it listen on 127.0.0.1 only;
  - give root a strong password;
  - turn off phpMyAdmin, or allow it from the server itself only.
- Use OMPS only on the unit's internal network and never expose it to the internet. PHP 7.2 and MariaDB 10.1 no longer receive security updates.

## 8. Upgrading to XAMPP 8.x later

The program itself needs no changes. You can move the data in either of two ways.

**Option 1: copy and re-import.**
1. Copy the program folder, `.env`, the `storage` folder and the attachments folder to the new installation.
2. Export the old database and import it into the new one.
3. Run `php omps migrate`, then `php omps env:check`.

**Option 2: restore from a backup.**
1. Make a backup on the old server.
2. Copy the zip and its folder into the backup folder on the new server.
3. Restore it on the **Backups** page.

## 9. Everyday commands

| Command | What it does |
|---|---|
| `php omps env:check` | Checks the environment |
| `php omps migrate` | Creates new tables after an upgrade (uses the upgrade account) |
| `php omps migrate:status` | Shows which upgrades have run and which are pending |
| `php omps seed` | Syncs base data (role permissions you have changed are not overwritten) |
| `php omps admin:create` | Adds a super administrator |
| `php omps key:generate` | Creates the encryption key (an existing key is never overwritten) |
| `php omps cleanup` | Removes temporary files, recycle-bin files older than 30 days and read notifications older than 180 days |
| `php omps schedule:run` | Sends reminders that are due and makes the daily automatic backup when it is due. Task Scheduler runs it every 5 minutes; `--at="2026-11-30 09:05"` simulates a given time. |
| `php omps backup:create` | Backs up the database and uploaded files now |
| `php omps backup:restore <file>` | Restores a backup from the backup folder (the current data is backed up first) |
