# Demo & POC Unit Tracker

A small web app (plain PHP + MySQL, no framework) that keeps track of the demo
and POC cameras you lend to salespeople, and emails reminders automatically.

## What it does

- **Units**: register each camera (model, serial number, asset tag). See where
  every unit is right now, and its full loan history.
- **Salespeople**: the people you send units to. They do not log in; they just
  get emails.
- **Loans**: "send out a unit" to a salesperson with a due date. Change the due
  date to extend, and mark it as returned when it comes back.
- **Overview**: how many units are out, overdue, due soon and in stock.
- **Team**: the people on your team who can log in.
- **Automatic emails** (the salesperson gets the email, your team address gets a copy):
  - a confirmation when a loan is registered (you can untick it per loan)
  - 7 days and 1 day before the due date
  - on the due date
  - when overdue: the day after, and then every 7 days until it is returned
  - all of these days can be changed in `config.php`

## The files

| File | What it is |
|---|---|
| `config.sample.php` | Settings template. You copy it to `config.php`. |
| `install.sql` | Creates the database tables. Run once. |
| `setup.php` | Creates the first login. Works only once. |
| `login.php`, `logout.php` | Log in and out. |
| `index.php` | Overview page. |
| `loans.php`, `loan_edit.php` | List loans; send out / extend / return a unit. |
| `units.php` | List, add and edit units. |
| `people.php` | List, add and edit salespeople. |
| `users.php` | Team logins and changing your password. |
| `cron.php` | The reminder script your host runs once a day. |
| `includes/` | Shared code: database connection, email sending, reminder rules, page layout. |
| `style.css` | The look of the pages. |
| `.htaccess` | Stops browsers from opening `config.php` and other private files. |

## Install guide

### 1. Create the database
1. Log in to your web host's control panel (cPanel, Plesk or similar).
2. Open **MySQL Databases** and create a new database, e.g. `unit_tracker`.
3. Create a database user with a strong password and give it **all privileges**
   on that database.
4. Write down: database name, user name, password and host (usually `localhost`).

### 2. Create the tables
1. Open **phpMyAdmin** from the control panel and click your new database.
2. Click the **Import** tab, choose `install.sql` and click **Go** (or open the
   **SQL** tab, paste the contents of `install.sql` and click **Go**).
3. You should now see 5 tables: `users`, `units`, `salespeople`, `loans`, `reminder_log`.

### 3. Fill in the settings
1. Make a copy of `config.sample.php` and name it `config.php`.
2. Open `config.php` in a text editor and fill in:
   - `db_name`, `db_user`, `db_pass` (and `db_host` if it is not `localhost`)
   - `base_url`: the address where the tracker will live
   - `team_email`: your team's address, which gets a copy of every email
   - `mail_from`: an address **on your own domain** (e.g. `noreply@yourdomain.com`),
     otherwise reminders tend to land in spam
   - `timezone`: e.g. `Europe/Stockholm`
   - `cron_key`: replace it with a long random word (only needed for step 6, option B)

### 4. Upload the files
Upload the whole `unit-tracker` folder (including `config.php`, the `includes`
folder and the `.htaccess` files) to your web space with the host's File Manager
or an FTP program such as FileZilla, e.g. to `public_html/unit-tracker/`.

### 5. Create your login
Open `https://your-domain/unit-tracker/` in a browser. The first time, it sends
you to **First-time setup**: enter your name, email and a password. After that,
log in, and add colleagues under **Team**.

Then add your units under **Units** and your salespeople under **Salespeople**,
and use **Send out a unit** for each loan.

### 6. Turn on the automatic reminders (cron job)
A "cron job" is a task your web host runs on a schedule. Set one up to run
once a day, for example at 08:00. In cPanel it is under **Cron Jobs**.

**Option A (best): command line.** Use this command, with your own path:
```
php /home/YOUR_ACCOUNT/public_html/unit-tracker/cron.php
```
(The control panel or File Manager shows your real path. Some hosts need the
full path to PHP, such as `/usr/local/bin/php`.)

**Option B: web address.** If your host only lets you schedule a web address,
use this (with the `cron_key` from `config.php`):
```
https://your-domain/unit-tracker/cron.php?key=YOUR_CRON_KEY
```

Running it more than once a day is harmless: each reminder is only sent once.

### 7. Test the emails
1. Add yourself as a salesperson, add a test unit and send it out to yourself
   with the confirmation box ticked. You should get the email within a minute.
2. Open the loan and click **Send reminder now** to test a reminder.
3. To test the cron job, run it once by hand (option B: open the web address in
   your browser). It prints what it sent.

If no email arrives, check your spam folder, then make sure `mail_from` is an
address on your own domain. If it still fails, switch to SMTP: in `config.php`
set `'mail_method' => 'smtp'` and fill in the `smtp_*` settings with an email
account from your host (the host's help pages list the server name and port).

## Good to know
- **Extending a loan**: open the loan, change **Due back** and save. The
  reminders start over for the new date.
- **Wrong entry?** A loan can be deleted on its page. A unit marked as returned
  by mistake can be undone.
- **Backups**: everything is in the database. Most hosts back it up; you can also
  use phpMyAdmin's **Export** tab now and then.
- **Testing without sending real email**: set `'mail_method' => 'log'`; emails are
  then written to `mail.log` in the tracker folder instead.
- Requires PHP 7.4 or newer (PHP 8 recommended) and MySQL 5.7+ or MariaDB.

## Automatic deploys from GitHub
Every push to the `main` branch uploads the changed files to your web host over
SSH (see `.github/workflows/deploy.yml`). It is switched off until you set it up:

1. On your host, make sure SSH access is turned on, and that `config.php` is
   already in the tracker folder (step 3 above).
2. Create an SSH key without a passphrase and add its public part to your
   account (cPanel: **SSH Access > Manage SSH Keys**; DirectAdmin/Inleed: the
   file `~/.ssh/authorized_keys`, see your host's SSH key guide). In cPanel, leave
   the passphrase empty, then click **Manage** next to the new public key and
   **Authorize** it.
3. Next to the new private key, click **View/Download** and copy the text that
   starts with `-----BEGIN` (not the PPK version).
4. In the GitHub repository, open **Settings > Secrets and variables > Actions**
   and under **Secrets** add:
   - `SSH_HOST`: your domain or the server name your host gives you
   - `SSH_PORT`: only if your host does not use port 22
   - `SSH_USER`: your hosting account user name
   - `SSH_PRIVATE_KEY`: the private key text from step 3
   - `DEPLOY_DIR`: the folder, ending with `/`, e.g. `public_html/unit-tracker/`
5. Under **Variables**, add `DEPLOY_ENABLED` with the value `true`.

`config.php` is never stored on GitHub and the deploy skips it, so a deploy
never touches your settings. Files are only added or updated, never deleted.
