<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `user_id` only ever identifies a staff User. Actions by other authenticated parties — e.g. a
     * candidate on the portal's `candidate` guard — are attributed through this polymorphic actor
     * instead, so an audit row can never point at an unrelated staff user who shares an id.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->nullableMorphs('actor');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropMorphs('actor');
        });
    }
};
