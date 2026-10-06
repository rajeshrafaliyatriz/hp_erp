<?php

namespace App\Traits;


trait Helpers
{
    public static function getMonths()
    {
        return [
            "Apr",
            "May",
            "Jun",
            "Jul",
            "Aug",
            "Sep",
            "Oct",
            "Nov",
            "Dec",
            "Jan",
            "Feb",
            "Mar",
        ];
    }

    /**
     * The one spelling of a month this system stores. F-137.
     *
     * `employee_monthly_salary_data.month` is a free-form varchar and live data
     * holds at least two incompatible formats:
     *
     *     SELECT DISTINCT month FROM employee_monthly_salary_data
     *       ->  'May'  'Aug'  'july'
     *
     * The payroll screen posts what getMonths() returns - 'Jul' - so the
     * seventeen rows stored as 'july' were unreachable by every query that
     * matches on month. That included F-109's duplicate-collapsing upsert, whose
     * whole purpose was those seventeen rows; the payslip delete; the payslip
     * PDF lookup; and My HR's ordering, where FIELD(month, 'Dec', ..., 'Jan')
     * scored 'july' as 0.
     *
     * `utf8mb4_unicode_ci` ignores case but not length, so 'Jul' != 'july' no
     * matter the collation. This is the only place that decides, and it is
     * deliberately generous about input and strict about output.
     *
     * Returns null for anything it cannot recognise rather than guessing - a
     * caller that gets null should refuse, not invent a month.
     */
    public static function canonicalMonth($month): ?string
    {
        $raw = strtolower(trim((string) $month));

        if ($raw === '') {
            return null;
        }

        foreach (self::getMonths() as $canonical) {
            $lower = strtolower($canonical);

            // 'jul' matches 'Jul', 'JULY', 'july' and 'July' alike. Comparing on
            // the three-letter prefix is what bridges the two stored formats.
            if ($raw === $lower || str_starts_with($raw, $lower)) {
                return $canonical;
            }
        }

        return null;
    }

    /**
     * Is this month in the January-March tail of an April-March payroll year?
     *
     * Was written inline as `in_array($request->month, ['Jan','Feb','Mar'])`,
     * which is case-sensitive - so a month stored as 'january' was filed under
     * the wrong year and became a second unreconcilable key for the same period.
     */
    public static function isNextCalendarYearMonth($month): bool
    {
        return in_array(self::canonicalMonth($month), ['Jan', 'Feb', 'Mar'], true);
    }

    public static function getYears() {
        return [
          2021,
          2022,
          2023,
          2024,
          2025
        ];
    }

    public static function getPairYears() {
        return [
            '2021'=>'2021-2022',
            '2022'=>'2022-2023',
            '2023'=>'2023-2024',
            '2024'=>'2024-2025',
            '2025'=>'2025-2026'
        ];
    }

    public static function getPF($totalAllowance) {
        // BASIC + GRADPAY + DA for MMIS
        $PF = 0;
        if($totalAllowance > 0){
            $PF =round(($totalAllowance * 12)/100);
        }
        if($PF>1800){
            $PF = 1800;
        }
        return $PF;
    }

    public static function getPT($totalAllowance,$gender="") {
        // Sum of all allowance plus
        $PT = 0;
        if($totalAllowance > 0){
            // PT calculation for male 
            if($gender=="M"){
                // if salary between 7501 - 10,000 Rs. 175/-
                if($totalAllowance > 7500 && $totalAllowance <= 10000){
                    $PT = 175;
                }
                // if salary Above 10,000 Rs. 200/-
                if($totalAllowance > 10000){
                    $PT = 200;
                }
            }
            // PT calculation for females salary Above 25000/- Rs. 200/-
            else if($gender=="F" && $totalAllowance > 25000){
                $PT = 200;
            }
        }
        return $PT;
    }

}
