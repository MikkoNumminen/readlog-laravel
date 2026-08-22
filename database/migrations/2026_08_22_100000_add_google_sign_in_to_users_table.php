<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google sign-in, and the flag that decides whose reading is public.
 *
 * .NET counterpart: the AspNetUserLogins table ASP.NET Core Identity creates for
 * external logins, plus the `picture` claim Program.cs captures from Google. This
 * port keeps the provider key on the user row instead of in a join table, because
 * there is exactly one provider and no plan for a second; a join table would be
 * three columns of ceremony for a one-to-one fact.
 *
 * `shares_publicly` has no counterpart at all. The source is a single-reader app
 * whose feed shows everything; this port lets anyone sign in, which turns that
 * feed into other people's reading history on a public page. New accounts are
 * private, and only an account that opts in reaches the feed. See decision 145.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Google's `sub`: stable for the life of the account, and the only
            // identifier Google documents as safe to key on. Nullable because a
            // seeded demo reader has never signed in to anything.
            $table->string('google_id')->nullable()->unique()->after('email_verified_at');
            $table->string('avatar_url')->nullable()->after('google_id');
            $table->boolean('shares_publicly')->default(false)->after('avatar_url');
        });

        // A Google account has no password here, and never gets one: there is no
        // local sign-in form to use it with. The column stays because Laravel's
        // stock user model and factory expect it, and because local accounts are
        // still an open TODO item ported from the .NET side.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'avatar_url', 'shares_publicly']);
        });
    }
};
