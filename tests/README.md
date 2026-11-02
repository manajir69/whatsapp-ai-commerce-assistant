# Isolated regression checks

Run with a PHP CLI that has the required extensions:

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*.php; do
  php "$test" || exit 1
done
python3 scripts/check-public.py
```

Fixtures create temporary copies/stubs to exercise request routing, stock/knowledge boundaries, provider configuration, owner replies, media handling, consent extraction, and notification service windows. They do not use a live configuration or production database.

These are regression fixtures, not full end-to-end tests of MySQL, real providers, WhatsApp delivery, or account billing. A deployment needs separate acceptance tests using development accounts.
