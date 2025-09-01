<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize existing data to avoid unique constraint conflicts on default 0
        DB::table('blackouts')
            ->where('outage_number', 0)
            ->update(['outage_number' => null]);

        Schema::table('blackouts', function (Blueprint $table): void {
            // Unique index on outage_number to prevent duplicates
            $table->unique('outage_number', 'blackouts_outage_number_unique');
            // Supporting indexes for common lookups
            $table->index(['area_id', 'city_id', 'address_id'], 'blackouts_area_city_address_idx');
            $table->index(['outage_date', 'outage_start_time'], 'blackouts_date_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blackouts', function (Blueprint $table): void {
            $table->dropUnique('blackouts_outage_number_unique');
            $table->dropIndex('blackouts_area_city_address_idx');
            $table->dropIndex('blackouts_date_time_idx');
        });
    }
};


