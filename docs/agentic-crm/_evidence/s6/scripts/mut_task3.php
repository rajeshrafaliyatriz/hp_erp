<?php

$mig = 'database/migrations/2026_12_11_100100_add_product_and_org_account_to_crm_leads.php';
$tests = ['tests/Feature/LeadLinkColumnsMigrationTest.php'];

return [
    ['id' => 'MIG-1 no column guard', 'file' => $mig, 'find' => 'if (! $this->hasColumn($column)) {', 'replace' => 'if (true) {', 'tests' => $tests, 'why' => 'a second run tries to add a column that exists'],
    ['id' => 'MIG-2 down() also drops description', 'file' => $mig, 'find' => "Schema::table('crm_leads', fn (Blueprint \$t) => \$t->dropColumn(\$column));", 'replace' => "Schema::table('crm_leads', fn (Blueprint \$t) => \$t->dropColumn([\$column, 'description']));", 'tests' => $tests, 'why' => 'down() removes something up() did not make'],
    ['id' => 'MIG-3 the index is not led by the tenant', 'file' => $mig, 'find' => "\$t->index(['sub_institute_id', \$column], \$index)", 'replace' => "\$t->index([\$column], \$index)", 'tests' => $tests, 'why' => 'a tenant-scoped read cannot use the index'],
    ['id' => 'MIG-4 no index guard', 'file' => $mig, 'find' => 'if (! $this->hasIndex($index)) {', 'replace' => 'if (true) {', 'tests' => $tests, 'why' => 'a second run tries to add an index that exists'],
    ['id' => 'MIG-5 up() has no table guard', 'file' => $mig, 'find' => "        if (! Schema::hasTable('crm_leads')) {\n            return;\n        }\n\n        foreach (self::COLUMNS as \$column => \$index) {\n            if (! \$this->hasColumn(\$column)) {", 'replace' => "        foreach (self::COLUMNS as \$column => \$index) {\n            if (! \$this->hasColumn(\$column)) {", 'tests' => $tests, 'why' => 'a host without the table fails'],
];
