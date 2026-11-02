# Privacy and publication scope

This edition was assembled from an explicit source-file allowlist. It has a fresh publication history; no history, backups, or release archives from the private application are included.

## Excluded

- Live `config.php`, environment files, passwords, tokens, API credentials, private keys, and stored provider credentials.
- Database contents, customer conversations, CRM exports, consent records, phone contacts, and payment records.
- Uploaded images/videos/documents, payment screenshots, media archives, logs, caches, and storage directories.
- Hosting control-panel URLs, server-specific filesystem paths, live dashboard links, business account identifiers, and release ZIP files.
- Internal handoff notes, debugging endpoints, and private deployment documentation.

## Anonymized

Business-specific UI text and domains were replaced with a generic demo identity and example.com addresses. Contact-like values in test fixtures use fictional example numbers. Test names and address strings were replaced where applicable. The interface illustration uses made-up data and is labelled as an illustration.

## Checks and limits

`scripts/check-public.py` rejects common credential patterns, runtime/archive files, contact formats from the original deployment region, and database data statements. It is a publication guard, not proof that all possible secrets or vulnerabilities can be detected. Review new files before any future public upload; `.gitignore` does not erase files already committed.

No production logs, screenshots, configuration, or database access is necessary to review this project.
