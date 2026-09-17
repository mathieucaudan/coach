# Connexion bancaire Crédit Agricole

Cette intégration utilise exclusivement GoCardless Bank Account Data (Account Information API). Les identifiants bancaires, mots de passe, codes SMS et codes 2FA sont saisis sur le site de la banque et ne transitent jamais par l’application.

## Configuration

1. Créer un compte Bank Account Data dans le portail GoCardless et créer des secrets API.
2. Copier `config.example.php` vers `config.php` et renseigner `GOCARDLESS_SECRET_ID`, `GOCARDLESS_SECRET_KEY`, `APP_URL` (URL HTTPS publique exacte) et `APP_ENV = 'production'`. Ces mêmes noms peuvent être fournis comme véritables variables d’environnement par l’hébergeur; lorsqu’elles sont non vides, les variables d’environnement ont priorité sur les constantes de `config.php`.
3. Appliquer `migrations/2026-09-17_banking.sql`, ou ouvrir la page **Migrations** avec un super administrateur.
4. Vérifier que l’URL `APP_URL/index.php?page=bank_callback` est accessible en HTTPS. Elle est transmise comme URL de retour à chaque connexion.

Les secrets restent uniquement dans `config.php`, ignoré par Git. Ne jamais les placer dans JavaScript, une URL ou un log.

## Connexion Crédit Agricole

Avec un compte `super_admin`, ouvrir **Comptabilité · Banque**, choisir la caisse régionale récupérée dynamiquement pour la France, puis cliquer **Connecter mon compte bancaire**. L’utilisateur est redirigé vers sa banque. Au retour, tous les comptes associés au consentement sont enregistrés. Une synchronisation récupère détails, IBAN éventuel, soldes, opérations comptabilisées et en attente.

Le consentement peut expirer. L’historique demeure disponible et l’interface indique qu’une nouvelle connexion est requise. Déconnecter désactive les synchronisations, tente de révoquer la connexion distante et conserve les opérations.

## Synchronisation automatique

Configurer un cron Hostinger trois ou quatre fois par jour :

```sh
/usr/bin/php /chemin/vers/public_html/bin/sync_bank_accounts.php
```

Le traitement isole les erreurs par compte, applique des retries bornés sur 429/5xx, et importe de façon idempotente. Les transactions sans identifiant reçoivent une empreinte stable. Une opération `pending` peut être rapprochée et convertie en `booked`.

## Développement et debug

Pour travailler sans compte réel, utiliser uniquement hors production :

```php
const APP_ENV = 'development';
const BANKING_MOCK_MODE = true;
```

Le fournisseur fictif contient deux comptes, des recettes, dépenses et une opération en attente. Le mode mock est refusé quand `APP_ENV` vaut `production`. Exécuter `php tests/run.php` pour les tests sans réseau et `php bin/sync_bank_accounts.php --mock` après création des données de connexion fictives.

Les événements utiles sont enregistrés dans `bank_sync_logs` et dans le journal PHP sans jetons, secrets, IBAN ni réponses brutes. En cas d’erreur, vérifier `last_error`, les événements `sync_failed`/`consent_expired`, la présence des secrets, HTTPS et la disponibilité de la caisse régionale. Les réponses brutes des seules transactions sont conservées dans `bank_transactions.raw_data` pour diagnostic administrateur.

Références officielles : documentation GoCardless Bank Account Data, endpoints `/token/new/`, `/token/refresh/`, `/institutions/`, `/agreements/enduser/`, `/requisitions/` et `/accounts/{id}/...`.
