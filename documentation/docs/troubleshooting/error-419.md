---
sidebar_position: 1
---

# Error 419 - Page Expired

Error **419 - Page Expired** in Laravel typically occurs due to issues with session or CSRF token validation. One of the most common causes is a mismatch between the browser URL and your environment (`.env`) configuration.

* * * * *

✅ Verify `.env` Configuration
-----------------------------

Ensure the following `.env` values **match exactly** what you are entering in the browser (including `http` vs `https`, subdomains, and ports):

```
APP_URL=https://your.domain.com
SESSION_DOMAIN=.your.domain.com
SANCTUM_STATEFUL_DOMAINS=your.domain.com

```

### Tips

-   Use a **dot-prefixed domain** (e.g., `.your.domain.com`) for `SESSION_DOMAIN` if you're using subdomains.

-   Don't include `http://` or `https://` in `SANCTUM_STATEFUL_DOMAINS`.

-   `APP_URL` **must** include the scheme (`http` or `https`).

* * * * *

🧼 Clear and Cache Config
-------------------------

After updating the `.env` file, run the following command to apply changes:

```
php artisan config:cache

```

* * * * *

🧪 Test It
----------

1.  Clear your browser cookies and cache (especially relevant for `SESSION_DOMAIN` changes).

2.  Refresh the page or retry the action that triggered the 419 error.

* * * * *

🛑 Still Seeing 419?
--------------------

Double-check:

-   If you're running the app in a **Docker container**, ensure `.env` and actual host URL match.

-   If you're using **HTTPS**, ensure the certificate is trusted and valid.

-   Check your **browser dev tools > Cookies** to confirm session cookies are being sent.

* * * * *

📌 Quick Example
----------------

If you're accessing the app using `https://fs-pbx.example.com`, then:

```
APP_URL=https://fs-pbx.example.com
SESSION_DOMAIN=.example.com
SANCTUM_STATEFUL_DOMAINS=fs-pbx.example.com

```


