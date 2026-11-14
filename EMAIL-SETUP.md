# Agrogear order email setup

Order email uses PHPMailer with Gmail SMTP. The sender account must have
Google 2-Step Verification enabled and an App Password created for Agrogear.
Do not use the account's normal Gmail password.

1. In the Google account that will send order email, enable 2-Step Verification
   and create an App Password at `https://myaccount.google.com/apppasswords`.
2. In Windows, open **Edit the system environment variables** and choose
   **Environment Variables**.
3. Add these user or system variables:
   - `AGROGEAR_GMAIL_ADDRESS` = `agrogear9@gmail.com` (or the Gmail
     account that owns the App Password)
   - `AGROGEAR_GMAIL_APP_PASSWORD` = the 16-character App Password
4. Restart Apache from the XAMPP Control Panel so it receives the variables.
5. Install PHP dependencies from this directory if needed:
   `composer install`

The site sends separate order emails to the buyer and store, each with a
formatted PDF attachment with the order items, total, buyer, delivery, and
payment-reference details. The buyer's address is taken from the delivery form.
If the store receives an order but the buyer does not, verify the address the
buyer entered and check that inbox's Spam folder. A successful SMTP submission
cannot guarantee delivery to the inbox. The App Password is read from the server
environment and must not be added to PHP files or committed.
By default, the admin order copy is sent to `AGROGEAR_GMAIL_ADDRESS`; set the optional
`AGROGEAR_ORDER_EMAIL` environment variable to use a different admin inbox.

The delivery form asks the buyer to provide the M-Pesa confirmation code.
Payments are still verified manually by the store.

## Deploying on Render

The repository includes a Render Blueprint (`render.yaml`) and Dockerfile.
In Render, create a new Blueprint and connect `agrogear9/agrogear`; Render will
build the PHP/Apache image. After deployment, add `AGROGEAR_GMAIL_APP_PASSWORD`
to the service's Environment settings using the App Password for
`agrogear9@gmail.com`, then save and redeploy. Never commit the App Password or
add it to the Dockerfile. Order email will not work until this secret is set.
The service uses Render's free web-service plan, which may sleep when idle.
