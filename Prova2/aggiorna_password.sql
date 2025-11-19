-- Script per aggiornare le password degli utenti di test Pay Steam
-- Esegui questo script in phpMyAdmin per impostare password facili da ricordare

USE pay_steam;

-- Aggiorna password utenti
-- mario123 (MD5: aeb34368c5d53aee32431b5386f71c56) - GIÀ CORRETTA
UPDATE utente SET password = 'aeb34368c5d53aee32431b5386f71c56' WHERE email = 'mario.rossi@email.it';

-- anna123 (MD5: 3bc20dc01341f7a7f7e10977d40934bc) - GIÀ CORRETTA
UPDATE utente SET password = '3bc20dc01341f7a7f7e10977d40934bc' WHERE email = 'anna.verdi@email.it';

-- sft123 (MD5: 458968a22f57f2ecc7fedc178d4570f3)
UPDATE utente SET password = '458968a22f57f2ecc7fedc178d4570f3' WHERE email = 'sft@ferrovie.it';

-- hotel123 (MD5: 331146468f3e703f4a6b99ffb35b4bb3)
UPDATE utente SET password = '331146468f3e703f4a6b99ffb35b4bb3' WHERE email = 'hotel.roma@email.it';

SELECT 'Password aggiornate con successo!' AS messaggio;
