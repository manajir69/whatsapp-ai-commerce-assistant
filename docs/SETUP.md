# Setup and evaluation

This is an anonymized reference copy. The original deployment and private data are not included.

## Requirements

- PHP 8.2+ with PDO MySQL, cURL, mbstring, OpenSSL, fileinfo, and JSON support.
- MySQL 5.7+ or compatible MariaDB, using utf8mb4.
- HTTPS hosting, a scheduler, a WooCommerce development store, a WhatsApp Cloud API account, and a supported AI provider.
- Optional GD and Tesseract for image hashes and local OCR. Review available server capabilities before relying on those paths.

## Configure a development installation

1. Copy `config.example.php` to `config.php` **locally**. Keep it ignored by Git.
2. Provide the environment variables documented in that file through your deployment system. Use separate development credentials and test recipients. `CONSOLE_PASSWORD` must be non-empty and unique.
3. Create an empty database and import `sql/schema.sql`. The other SQL files are additive update scripts; inspect them before applying to an existing database. Some tables are initialized lazily by the relevant module.
4. Access `owner.php` and sign in. Set the business number, the associated Meta Phone Number ID, and a different operator notification number. The example has no usable defaults for these values.
5. Configure the AI provider and store instructions. Sync a test catalog with `php sync_products.php`.
6. Register the HTTPS `webhook.php` URL with your own Meta app. Configure the verification value and App Secret, subscribe to the required events, and validate signatures. Phone-app echoes require a supported coexistence setup and the relevant subscription.
7. On a server with cron, schedule `php /absolute/path/to/cron.php` every minute. Do not put passwords in public cron URLs. Use `php sync_products.php` for catalog synchronization as needed.
8. For owner alerts outside the service window, configure an approved two-body-variable template with the configured name and language on the **sending number's WABA**. `owner_workspace_alert` / `en` are example defaults, not an included Meta approval. Variables are the alert reference and its details.
9. Confirm read-only connection checks, test routing, delivery-failure handling, stock scope, and consent before using real contacts.

## Server access controls

Block direct web access to `config.php`, `.env*`, `sql/`, `tests/`, and runtime data. Protect media storage, disable directory indexing, use HTTPS and secure session settings, and review admin authentication/rate limiting for your deployment. Keep any development installer inaccessible after setup. The included `install.php` is authenticated but runs schema and external connection checks; do not expose it unnecessarily.

`rules.dry_run=true` only controls ordinary WooCommerce stock-write paths. It is **not a messaging sandbox**: explicit operator stock actions can force website updates, and connected messaging paths can contact recipients. Use an isolated store and test numbers.

## Production readiness

These are deployment instructions, not a completed security audit. Review least-privilege credentials, authentication hardening, backup/restore, retention, account billing, consent requirements, webhook delivery, and operational monitoring before a production rollout. No provider or Meta approval is bundled with this repository.
