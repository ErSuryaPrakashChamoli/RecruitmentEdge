<?php

use App\Http\Controllers\PrivateFileController;
use App\Models\AuditLog;
use App\Services\Lifecycle\LifecycleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Phase 8.9 (P89-DQ-016): no test writes to the real storage tree (offer letters, the
        // seeded standard template, uploads), which Docker builds and developers' disks would keep.
        Storage::fake('local');
        Storage::fake('public');
        // A faked disk loses the signed private-file URL builder the application registers at boot.
        PrivateFileController::registerTemporaryUrls();
    })
    ->afterEach(function (): void {
        // Phase 8.9: the default audit actor kind is process-wide; a test that starts a command or a
        // job by hand must also finish it (CommandFinished / JobAttempted), or later tests' audit
        // rows are attributed to `console` / `queue`.
        // (Thrown rather than asserted, so a test that expects no assertions stays valid.)
        if (AuditLog::defaultActorKind() !== null) {
            throw new LogicException('A test left the audit actor kind set; finish the command or job it started.');
        }
    })
    ->in('Feature');

// Phase 8.9 (ED-10): MySQL concurrency tests commit for real (no RefreshDatabase transaction) —
// see phpunit.concurrency.xml and tests/Concurrency/Race.php.
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
 * P810-RC-02: JSON compared by meaning — object keys in any order (MySQL's JSON type returns them
 * in its own order, and no contract that uses this depends on it), lists in their own order,
 * scalars compared strictly. Use it only where key order is not part of the behaviour under test.
 */
expect()->extend('toBeJsonEquivalent', function (mixed $expected) {
    expect(rc02CanonicalJson($this->value))->toBe(rc02CanonicalJson($expected));

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * P810-RC-02: object keys sorted at every level; lists keep their order.
 */
function rc02CanonicalJson(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    $value = array_map(rc02CanonicalJson(...), $value);

    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

/**
 * Phase 8.3: arranges lifecycle state a test needs as a precondition (e.g. "the requisition is
 * already closed") without going through the authoritative service. Only for fixtures — the
 * behaviour under test must still use the real service path.
 *
 * @template T
 *
 * @param  Closure(): T  $arrange
 * @return T
 */
function lifecycleFixture(Closure $arrange): mixed
{
    return LifecycleGuard::allow($arrange);
}
