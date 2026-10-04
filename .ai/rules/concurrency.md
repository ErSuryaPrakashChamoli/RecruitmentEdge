---
paths:
  - 'tests/Concurrency/**'
---

# Concurrency

## MySQL concurrency harness (ED-10)
Races are proven on MySQL, never SQLite: `vendor/bin/pest -c phpunit.concurrency.xml` against a throwaway database whose name must end in `_concurrency`. tests/Concurrency/Race.php forks with pcntl. The child uses a separate 'race' connection and is ended with SIGKILL. The barrier is a PROCESSLIST "waiting for lock" streak, because INNODB_TRX does not show waits on primary-key lookups. A lock-order test must check performance_schema.data_locks while blocked, or it passes on broken code. There is no RefreshDatabase here: tests commit for real and clean up after themselves. Every new race test must fail on the pre-fix code.

## Race contenders reload what the holder changed in memory
The contender is a fork: it inherits the parent's memory, including models and TenantContext the holder already changed in its uncommitted transaction (e.g. CommercialChange resets the context tenant). Reload them from the database at the start of the contender, as a new request would. Otherwise the contender decides on uncommitted state and never blocks.
