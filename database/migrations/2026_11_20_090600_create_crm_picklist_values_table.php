<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock picklist values recovered directly from the legacy CRM's language
 * files/dashboards (languages/en_us/Leads.php, Accounts.php, Campaigns.php,
 * Vtiger.php, include/ComboStrings.php) - confirmed, not guessed, but
 * flagged for an admin to verify/adjust against the real legacy admin
 * screens before go-live, since admins commonly add values post-install and
 * this repo has no live-database access to the legacy install.
 *
 * Keyed directly by (module, field_key) rather than a field-definitions FK -
 * field_key is a fixed, hardcoded real column name (lead_status, industry,
 * etc.), known at migration time, not a runtime-defined custom field. Custom
 * fields go through the separate, pre-existing tblcustom_fields/
 * g2g_custom_field_values engine (see config/platform_services.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_picklist_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module', 30); // leads|contacts|organizations|campaigns
            $table->string('field_key', 50);
            $table->string('value', 100);
            $table->string('label', 100);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('status')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();

            $table->unique(['module', 'field_key', 'value'], 'crm_picklist_unique');
        });

        $now = now();
        $rows = [];

        $seed = function (string $module, string $fieldKey, array $values) use (&$rows) {
            foreach (array_values($values) as $i => $value) {
                $rows[] = [
                    'module' => $module,
                    'field_key' => $fieldKey,
                    'value' => $value,
                    'label' => $value,
                    'sort_order' => $i + 1,
                    'is_default' => false,
                    'status' => true,
                ];
            }
        };

        $industry = [
            'Apparel', 'Banking', 'Biotechnology', 'Chemicals', 'Communications', 'Construction',
            'Consulting', 'Education', 'Electronics', 'Energy', 'Engineering', 'Entertainment',
            'Environmental', 'Finance', 'Food & Beverage', 'Government', 'Healthcare', 'Hospitality',
            'Insurance', 'Machinery', 'Manufacturing', 'Media', 'Not For Profit', 'Recreation',
            'Retail', 'Shipping', 'Technology', 'Telecommunications', 'Transportation', 'Utilities',
        ];
        $rating = ['Hot', 'Warm', 'Cold'];

        $seed('leads', 'lead_status', [
            'Attempted to Contact', 'Contacted', 'Junk Lead', 'Lost Lead',
            'Not Contacted', 'Pre Qualified', 'Qualified',
        ]);
        $seed('leads', 'lead_source', [
            'Cold Call', 'Existing Customer', 'Self Generated', 'Employee', 'Partner',
            'Public Relations', 'Direct Mail', 'Conference', 'Trade Show', 'Web Site',
            'Word of mouth', 'Other',
        ]);
        $seed('leads', 'rating', $rating);
        $seed('leads', 'salutation', ['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Prof.']);
        $seed('leads', 'industry', $industry);

        $seed('contacts', 'salutation', ['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Prof.']);
        $seed('contacts', 'lead_source', [
            'Cold Call', 'Existing Customer', 'Self Generated', 'Employee', 'Partner',
            'Public Relations', 'Direct Mail', 'Conference', 'Trade Show', 'Web Site',
            'Word of mouth', 'Other',
        ]);

        $seed('organizations', 'account_type', [
            'Analyst', 'Competitor', 'Customer', 'Integrator', 'Investor', 'Press', 'Prospect', 'Reseller',
        ]);
        $seed('organizations', 'industry', $industry);
        $seed('organizations', 'rating', $rating);

        $seed('campaigns', 'campaign_type', [
            'Conference', 'Webinar', 'Trade Show', 'Public Relations', 'Partners',
            'Referral Program', 'Advertisement', 'Banner Ads', 'Direct Mail', 'Email',
            'Telemarketing', 'Others',
        ]);
        $seed('campaigns', 'campaign_status', ['Planning', 'Active', 'Inactive', 'Completed', 'Cancelled']);
        $seed('campaigns', 'expected_response', ['Excellent', 'Good', 'Average', 'Poor']);

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('crm_picklist_values')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_picklist_values');
    }
};
