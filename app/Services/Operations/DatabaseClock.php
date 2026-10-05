<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;

/**
 * Production readiness (PR-02): how far the database session's clock is from UTC.
 *
 * The application keeps every time in UTC (`app.timezone`) and shows tenant-local times on top. A
 * MySQL session in another zone evaluates NOW() / CURRENT_TIMESTAMP in that zone and converts
 * TIMESTAMP columns through it. Values the application writes still read back unchanged, but
 * database-side time disagrees with the application's, and the stored instants depend on the
 * server's zone. `DB_TIMEZONE` (or the server's default_time_zone) pins it. Changing it on a
 * database that already holds data changes how existing TIMESTAMP values read, so it is decided
 * with the production-copy rehearsal, never switched blindly.
 */
class DatabaseClock
{
    /**
     * Minutes from UTC of the default connection's session; null where there is no session time zone
     * (SQLite).
     */
    public function utcOffsetMinutes(): ?int
    {
        $db = DB::connection();

        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        return (int) $db->scalar('select timestampdiff(minute, utc_timestamp(), now())');
    }
}
