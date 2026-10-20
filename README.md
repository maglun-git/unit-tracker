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
- **Team**: the people on your team who can log in. New people can create an
  account with **Register** on the log-in page and use it straight away (the
  team address gets an email when someone registers).
- **Admin** (only for admins): add and remove users, set a new password for
  someone, make others admin, send reminder emails right away, and see the
  latest emails sent. The account made in `setup.php` is the first admin.
- **Forgot password**: a link on the log-in page emails a one-time reset link
  that works for 1 hour.
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
| `migrate.php` | Upgrades an existing database to match the code. The GitHub deploy runs it automatically; by hand: `php migrate.php`. |
| `upgrade-2026-10-register.sql`, `upgrade-2026-10-admin.sql` | Only for databases made before October 2026: add what Register, Forgot password and Admin need. Same changes as `migrate.php`, if you prefer phpMyAdmin. |
| `setup.php` | Creates the first login. Works only once. |
| `login.php`, `logout.php` | Log in and out. |
| `register.php` | Create an account (can log in straight away). |
| `admin.php` | Admin portal: users, reminder emails, email log. Admins only. |
| `forgot.php`, `reset.php` | "Forgot password" and choosing a new password from the emailed link. |
| `index.php` | Overview page. |
| `loans.php`, `loan_edit.php` | List loans; send out / extend / return a unit. |
| `units.php` | List, add and edit units. |
| `people.php` | List, add and edit salespeople. |
| `users.php` | List of team logins and changing your own password. |
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
Emails are sent with [PHPMailer](https://github.com/PHPMailer/PHPMailer) (in
`includes/PHPMailer/`). To keep them out of spam:

1. In DirectAdmin, open **E-mail Manager > E-mail Accounts** and create a
   mailbox on your domain, e.g. `noreply@yourdomain.se`.
2. In `config.php` on the host set `mail_from` and `smtp_user` to that address,
   `smtp_pass` to its password, `'mail_method' => 'smtp'`, and `smtp_host` to
   your mail server (DirectAdmin shows it as the outgoing/SMTP server).
3. In DirectAdmin, make sure **DKIM** is turned on for the domain
   (E-mail Manager > DKIM, or ask Inleed support). SPF is normally set already.
4. Log in, open **Admin** and click **Send test email**. If it fails, the page
   shows the reason and the conversation with the mail server.

Then:
1. Add yourself as a salesperson, add a test unit and send it out to yourself
   with the confirmation box ticked. You should get the email within a minute.
2. Open the loan and click **Send reminder now** to test a reminder.
3. To test the cron job, run it once by hand (option B: open the web address in
   your browser). It prints what it sent.

If no email arrives, use **Admin > Send test email**: it lists settings that
often cause problems and shows the mail server's answer.

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
SSH (see `.github/workflows/deploy.yml`). It only runs when the GitHub variable
`DEPLOY_ENABLED` is `true` (Settings > Secrets and variables > Actions > Variables).

Setting it up (done once; example values are for Inleed):

1. Make sure `config.php` is already in the tracker folder on the host (step 3 above).
2. On your own computer, create a key without a passphrase and give the public
   half to the host:
   ```
   ssh-keygen -t ed25519 -f ~/.ssh/unittracker_deploy -N "" -C unittracker-deploy
   ssh-copy-id -i ~/.ssh/unittracker_deploy.pub -p 2020 YOURUSER@nsX.inleed.net
   ```
3. In GitHub, open **Settings > Secrets and variables > Actions** and add these secrets:
   - `SSH_HOST`: the server name, e.g. `ns15.inleed.net`
   - `SSH_PORT`: `2020` on Inleed (leave it out if your host uses 22)
   - `SSH_USER`: your DirectAdmin (or cPanel) user name
   - `SSH_PRIVATE_KEY`: the private half (on a Mac: `pbcopy < ~/.ssh/unittracker_deploy`, then paste)
   - `DEPLOY_DIR`: the folder, ending with `/`, e.g. `domains/yourdomain.se/public_html/unit-tracker/`
4. Under **Variables**, add `DEPLOY_ENABLED` with the value `true`.

To deploy by hand, open the **Actions** tab, pick **Deploy to web host** and click
**Run workflow**. `config.php` is never stored on GitHub and the deploy skips it,
so a deploy never touches your settings. Files are only added or updated, never
deleted.
