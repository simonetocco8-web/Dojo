# PHP Admin Starter (con recupero password)

Starter in PHP + MySQL con:
- Login sicuro + ruolo admin
- Dashboard responsive (Bootstrap 5)
- Gestione utenti base
- Recupero password via email (token selector/validator, 1h)

## Installazione
1. Importa SQL:
   ```sql
   SOURCE database/install.sql;
   SOURCE database/password_reset.sql;
   ```
2. Configura `config/env.php` con le credenziali DB e `mail.from`.
3. Se l'app è in sottocartella, imposta `app.base_url` (es. `/adminapp`).
4. Apri `index.php` → login (admin@example.com / Admin123!).

## Recupero password
- `password_forgot.php`: invia link di reset all'email se esiste.
- `password_reset.php`: imposta la nuova password (token valido 1h).
- Se `mail()` non è disponibile, i messaggi vengono scritti in `storage/mail.log`.

## Sicurezza
- Password hash (`password_hash`), CSRF, session hardening.
- Token reset conservati come hash (validator hash SHA-256).

## Struttura
```
/config/env.php
/core/{auth.php,db.php,security.php,token.php,mailer.php}
/partials/{header.php,footer.php}
/assets/style.css
/database/{install.sql,password_reset.sql}
/ewelink/{connect.php,callback.php,devices.php,device_action.php,disconnect.php}
index.php
dashboard.php
users.php
password_forgot.php
password_reset.php
logout.php
.htaccess
```

## Integrazione eWeLink
- Compila la sezione `ewelink` in `config/env.php` con `client_id`, `client_secret` e l'URL pubblico di callback (es. `https://tua-app/ewelink/callback.php`).
- Importa/aggiorna lo schema database con `database/install.sql` per creare la tabella `ewelink_tokens`.
- Da menu “eWeLink” (visibile agli admin) collega l'account tramite OAuth 2.0.
- Dopo l'autorizzazione potrai vedere l'elenco dei dispositivi Sonoff/eWeLink e inviare comandi on/off.
