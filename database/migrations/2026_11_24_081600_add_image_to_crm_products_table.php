<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 audit gap: vtiger's Product/Service "Product Image" field - no
 * file upload anywhere in the port. Stores only the filename (same
 * convention as every other image column in this app, e.g.
 * OrganizationProfileController's logo) - the URL is resolved on read via
 * the 'digitalocean' disk, never persisted (a signed/base URL can change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_products', function (Blueprint $table) {
            $table->string('image')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('crm_products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
