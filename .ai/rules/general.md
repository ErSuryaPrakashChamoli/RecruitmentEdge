---
paths:
  - composer.json
---

# General

## phpoffice/phpword and phpoffice/phpspreadsheet require ext-gd
This environment doesn't have PHP's gd extension by default. `composer require phpoffice/phpword phpoffice/phpspreadsheet` fails platform-req checks until it's installed (`sudo apt-get install -y php8.5-gd` on this box, or the matching php-version package). Composer can't be run with sudo from an agent's sandboxed Bash tool (needs an interactive TTY for the password) — this has to be done by the user in their own terminal, or the packages swapped for a lighter dependency-free alternative.
