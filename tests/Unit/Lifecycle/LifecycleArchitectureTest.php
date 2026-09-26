<?php

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Phase 8.3: a domain event may only be heard once the fact it announces is committed — no
 * listener (automation, communication, calendar, Outcome Loop, Hiring Memory) can act on a change
 * that is later rolled back.
 */
arch('every domain event dispatches after the transaction commits')
    ->expect('App\Events')
    ->toImplement(ShouldDispatchAfterCommit::class);
