<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RS2-2 signup persistence (Variant A): the account is created
     * at the register wizard's identity checkpoint (Steps 1-3),
     * before the owner gives an address. The Location step in
     * onboarding collects the address afterwards, so the five
     * address columns must be able to hold NULL in the interval.
     * A NULL street is the Location step's done-ness predicate
     * (App\Support\Onboarding); every address reader must treat
     * NULL as "not set yet", never as an empty address.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('country')->nullable()->change();
            $table->string('street')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->string('postal_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('country')->nullable(false)->change();
            $table->string('street')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('state')->nullable(false)->change();
            $table->string('postal_code')->nullable(false)->change();
        });
    }
};
