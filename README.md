# event.egor-zvada.ru

Ticket module for events and competitions.

## Stack

- PHP 8+
- SQLite
- No Composer or Node build step required

## Local run

```bash
php -S 127.0.0.1:8080
```

Open:

```text
http://127.0.0.1:8080
```

## Admin

```text
/admin/
```

By default the admin user is read from environment variables:

```text
ADMIN_USER
ADMIN_PASSWORD
ADMIN_PASSWORD_HASH
```

On first SQLite initialization the app creates:

```text
admin / change-me-now
controller / controller-change-me
```

Change these immediately in the admin panel by creating real staff users and disabling/removing the temporary ones.

## SMTP

Create `config/mail.php` from `config/mail.example.php`:

```php
<?php

return [
  'host' => 'smtp.example.ru',
  'port' => 587,
  'username' => 'tickets@example.ru',
  'password' => 'password',
  'encryption' => 'tls',
  'from_email' => 'tickets@example.ru',
  'from_name' => 'Egor Zvada Events',
];
```

When SMTP is configured, ticket emails are sent after booking. The QR code points back to the ticket verification page.
If SMTP is not configured, the user still gets the ticket page immediately and can save/screenshot the QR code.

## Structure

```text
app/                 SQLite, tickets, SMTP, uploads, helpers
partials/            Public head, header, footer
admin/               Staff sessions, events, tickets, users
assets/css/          Shared interface styles
assets/js/           Seat map behavior
assets/img/uploads/  Event covers and galleries
data/                SQLite database
```

## API

```text
/api.php?resource=events
/api.php?resource=event&slug=tavrida-tech-showcase
/api.php?resource=ticket&code=ABC123
```

## Wallets

Apple Wallet and Google Wallet are not one-line features. Apple requires a Pass Type ID certificate and signed `.pkpass` generation. Google Wallet requires an issuer account and API credentials. The ticket data model is ready for that, but no fake wallet buttons are shown.
