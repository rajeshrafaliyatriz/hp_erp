<?php

namespace App\Services\Idms;

/** Keyword and regex classification, used when the AI is off, fails, or is not confident. */
class IdmsRuleBasedClassifier
{
    public function classify(string $text, string $originalFileName, array $availableDepartments): array
    {
        $haystack = mb_strtolower($text . ' ' . $originalFileName);

        $detectedType = 'General Correspondence';
        $typeKeywords = [
            'Contract' => ['contract', 'agreement', 'service level agreement', 'amc', 'memorandum'],
            'Invoice' => ['invoice', 'bill', 'receipt', 'tax invoice', 'gstin'],
            'Policy' => ['policy', 'guidelines', 'standard operating procedure', 'sop', 'code of conduct'],
            'Circular' => ['circular', 'notification', 'office order', 'advisory'],
            'Minutes of Meeting' => ['minutes of meeting', 'meeting agenda', 'proceedings'],
            'Report' => ['annual report', 'audit report', 'evaluation report', 'progress report', 'summary report'],
            'Academic Syllabus' => ['syllabus', 'curriculum', 'course outline', 'lesson plan'],
            'Question Paper' => ['question paper', 'examination', 'test paper', 'midterm', 'final exam', 'marking scheme'],
            'Certificate' => ['certificate of', 'bonafide', 'completion certificate', 'transfer certificate', 'leaving certificate'],
        ];
        foreach ($typeKeywords as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($haystack, $kw)) {
                    $detectedType = $type;
                    break 2;
                }
            }
        }

        $academicYear = null;
        if (preg_match('/\b(20\d{2})[-–](20\d{2}|\d{2})\b/u', $text . ' ' . $originalFileName, $m)) {
            $academicYear = $m[0];
        }

        $documentDate = null;
        if (preg_match('/\b(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](20\d{2})\b/', $text, $dm)) {
            $documentDate = checkdate((int) $dm[2], (int) $dm[1], (int) $dm[3])
                ? sprintf('%04d-%02d-%02d', $dm[3], $dm[2], $dm[1]) : null;
        } elseif (preg_match('/\b(20\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})\b/', $text, $dm)) {
            $documentDate = checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])
                ? sprintf('%04d-%02d-%02d', $dm[1], $dm[2], $dm[3]) : null;
        }

        $matchedDept = '';
        foreach ($availableDepartments as $dept) {
            if ($dept !== '' && str_contains($haystack, mb_strtolower($dept))) {
                $matchedDept = $dept;
                break;
            }
        }

        $tags = [$detectedType];
        if ($academicYear) {
            $tags[] = $academicYear;
        }
        if ($matchedDept) {
            $tags[] = $matchedDept;
        }
        if (str_contains($haystack, 'maintenance')) {
            $tags[] = 'Maintenance';
        }
        if (str_contains($haystack, 'lab')) {
            $tags[] = 'Computer Lab';
        }
        if (str_contains($haystack, 'amc')) {
            $tags[] = 'AMC';
        }
        $tags = array_values(array_unique($tags));

        return [
            'document_type' => $detectedType,
            'category' => 'Administrative',
            'department' => $matchedDept,
            'subject' => pathinfo($originalFileName, PATHINFO_FILENAME),
            'document_date' => $documentDate,
            'academic_year' => $academicYear,
            'people' => [],
            'organization' => '',
            'project' => null,
            'keywords' => $tags,
            'lifecycle_status' => 'active',
            'suggested_tags' => $tags,
            'confidence' => 0.45,
            'summary' => 'Auto-classified using rule-based keyword extraction.',
        ];
    }
}
