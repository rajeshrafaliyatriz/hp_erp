<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Illuminate\Support\Str;

$company = "Kesar Ideal Skill School";
$slug = Str::slug($company);
echo "Slug: {$slug}\n";
echo "Website: https://www.{$slug}.edu.in\n";
echo "Email: principal@{$slug}.edu.in\n";
