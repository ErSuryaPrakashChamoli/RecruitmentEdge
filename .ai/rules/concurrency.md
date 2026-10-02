---
paths:
  - 'tests/Concurrency/**'
---

# Concurrency

## MySQL concurrency harness (ED-10)
Races are proven on MySQL, never SQLite: `vendor/bin/pest -c phpunit.concurrency.xml` against a throwaway database whose name must end in `_concurrency`. tests/Concurrency/Race.php forks with pcntl. The child uses a separate 'race' connection and is ended with SIGKILL. The barrier is a PROCESSLIST "waiting for lock" streak, because INNODB_TRX does not show waits on primary-key lookups. A lock-order test must check performance_schema.data_locks while blocked, or it passes on broken code. There is no RefreshDatabase here: tests commit for real and clean up after themselves. Every new race test must fail on the pre-fix code.
