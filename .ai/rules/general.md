---
paths:
  - composer.json
  - docker-compose.yml
---

# General

## phpoffice/phpword and phpoffice/phpspreadsheet require ext-gd
This environment doesn't have PHP's gd extension by default. `composer require phpoffice/phpword phpoffice/phpspreadsheet` fails platform-req checks until it's installed (`sudo apt-get install -y php8.5-gd` on this box, or the matching php-version package). Composer can't be run with sudo from an agent's sandboxed Bash tool (needs an interactive TTY for the password) — this has to be done by the user in their own terminal, or the packages swapped for a lighter dependency-free alternative.

## Every queue must have a worker (two-worker topology)
Workers: `queue` = communications,automation,default (--timeout=120); `queue-background` = intelligence,integrations,default (--timeout=300); DB_QUEUE_RETRY_AFTER (330) must exceed the longest timeout. A new job or queued listener on a new queue name must be added to a worker here and in docs/phase-7-production-readiness.md — tests/Feature/Lifecycle/QueueTopologyTest.php reads docker-compose.yml and fails otherwise. Slow provider work (AI, embeddings, calendar, job boards) goes on intelligence/integrations, never beside candidate messages.
