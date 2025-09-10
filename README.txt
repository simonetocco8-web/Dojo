# Modulo utenti: modifica + soft delete

File inclusi:
- users.php (lista/creazione + soft delete + cestino)
- user_edit.php (modifica utente)
- user_delete.php (imposta deleted_at)
- user_restore.php (ripristina)
- migrate_add_user_fields.sql (aggiunge nome, cognome, telefono, dipartimento)
- migrate_soft_delete.sql (aggiunge deleted_at)

Installazione:
1) Copia i file nella root della tua app (accanto a index.php).
2) Esegui le migrazioni necessarie nel DB:
   SOURCE migrate_add_user_fields.sql;
   SOURCE migrate_soft_delete.sql;

Uso:
- Lista utenti standard: /users.php (mostra solo deleted_at IS NULL)
- Cestino: /users.php?trash=1 (mostra deleted_at IS NOT NULL)
- Elimina: bottone "Elimina" (POST → user_delete.php, CSRF protetto)
- Ripristina: bottone "Ripristina" (POST → user_restore.php, CSRF protetto)
- Non è permessa l'auto-eliminazione dell'utente loggato.
