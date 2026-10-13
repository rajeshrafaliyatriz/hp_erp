<?php

namespace App\Services\Documents\Understanding;

/**
 * Keyword classification, for when AI is unconfigured, refuses, or returns a
 * type this app does not recognise. Never the first choice - `DocumentClassificationService`
 * only reaches this after `AiModelClient` has had its turn - but a document
 * always leaves the pipeline with SOME classification rather than none,
 * exactly as the reference implementation (next_lms_erp's IDMS) does with its
 * own `RuleBasedClassifier`.
 */
class RuleBasedClassifier
{
    /**
     * Ordered so the first match wins - 'offer letter' must be tested before
     * the bare word 'letter' would ever be (it is not, but the principle is
     * why specific phrases are listed ahead of generic ones throughout).
     *
     * @var array<string, string[]>
     */
    private const KEYWORDS = [
        'payslip' => ['payslip', 'pay slip', 'salary slip', 'net pay', 'gross earnings'],
        'offer_letter' => ['offer letter', 'appointment letter', 'letter of appointment'],
        'form16' => ['form 16', 'form16', 'form no. 16', 'tds certificate'],
        'salary_certificate' => ['salary certificate'],
        'resume' => ['curriculum vitae', 'resume', 'résumé', 'work experience', 'professional summary'],
        'id_proof' => ['aadhaar', 'aadhar', 'pan card', 'permanent account number', 'passport no', 'driving licence', "voter id"],
        'certificate' => ['certificate of completion', 'certify that', 'this is to certify', 'certification'],
        'contract' => ['this agreement', 'terms and conditions', 'party of the first part', 'non-disclosure'],
        'invoice' => ['invoice', 'invoice no', 'bill to', 'amount due'],
        'policy' => ['policy', 'this policy applies'],
    ];

    /** Confidence given to a rule match - deliberately below the AI threshold in config('documents.processing.confidence_threshold'). */
    private const CONFIDENCE = 0.45;

    /**
     * @return array{document_type: ?string, confidence: float, summary: ?string}
     */
    public function classify(string $text): array
    {
        $haystack = mb_strtolower($text);

        if (trim($haystack) === '') {
            return ['document_type' => null, 'confidence' => 0.0, 'summary' => null];
        }

        foreach (self::KEYWORDS as $type => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($haystack, $phrase)) {
                    return [
                        'document_type' => $type,
                        'confidence' => self::CONFIDENCE,
                        'summary' => null,
                    ];
                }
            }
        }

        return ['document_type' => null, 'confidence' => 0.0, 'summary' => null];
    }
}
