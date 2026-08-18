<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| .NET counterpart: the Image property on Models/ApplicationUser.cs, which extends
| IdentityUser with the two profile fields ReadLog displays (Name and Image).
|
| Laravel's stock users table already has name, email and timestamps, so only the
| avatar column is missing. Everything else IdentityUser brings (security stamps,
| lockout counters, external-login rows) belongs to authentication, which is out of
| scope for version 1; see DECISIONS.md and TODO.md.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('image')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
