# Agrogear order email setup

Order email is sent through the Brevo transactional email API over HTTPS.
Render's free web services block outbound SMTP ports, so Gmail SMTP and its
App Password cannot be used from the free service.

1. Create a Brevo account and verify `agrogear9@gmail.com` as a sender. If
   Brevo requires domain authentication for that address, use a sender address
   on a domain you control and authenticate that domain with Brevo.
2. Create a Brevo API key in the Brevo dashboard. Keep it secret.
3. Configure these environment variables:
   - `AGROGEAR_EMAIL_SENDER` = the sender address verified in Brevo
   - `AGROGEAR_BREVO_API_KEY` = the Brevo API key
   - `AGROGEAR_ORDER_EMAIL` = optional store inbox for admin order copies;
     defaults to the sender address
4. For local XAMPP, add the variables in Windows **Environment Variables**,
   then restart Apache from the XAMPP Control Panel.
5. For Render, add `AGROGEAR_BREVO_API_KEY` in the service's Environment
   settings and save/redeploy. Do not put the key in `render.yaml`, PHP files,
   or GitHub.

The site sends separate order emails to the buyer and store, each with a
formatted PDF attachment containing the order items, total, buyer, delivery,
and payment-reference details. A successful API submission means Brevo
accepted the message; it does not guarantee delivery to the inbox. Check the
Brevo transactional email logs and the recipient's Spam folder if a message
does not arrive.

The delivery form asks the buyer to provide the M-Pesa confirmation code.
Payments are still verified manually by the store.

The Render Blueprint (`render.yaml`) and Dockerfile build the PHP/Apache image.
The service uses Render's free web-service plan, which may sleep when idle.
See [Brevo's transactional email API documentation](https://developers.brevo.com/reference/send-transac-email).
