<?php

declare(strict_types=1);

namespace App\Support\Workgroups;

/** Exact, versioned initial production survey definition. */
final class BackToBasicsSurveyBlueprint
{
    public const TITLE = 'MBFD Back to Basics / Driver Engineer Instructor Survey';

    /** @return array<string,mixed> */
    public static function metadata(): array
    {
        return [
            'title' => self::TITLE,
            'description' => 'This 15-question survey is anonymous/de-identified in reporting. Completion may be tracked separately to enforce one response per eligible member. Demographic answers are optional and reported only when the subgroup is sufficiently large.',
            'status' => 'draft',
            'is_anonymous' => true,
            'minimum_subgroup_size' => 3,
            'demographic_fields' => [
                ['key' => 'current_rank', 'label' => 'Current Rank', 'options' => ['Firefighter', 'Lieutenant', 'Captain', 'Chief Officer', 'Other', 'Prefer not to answer']],
                ['key' => 'de_experience', 'label' => 'Driver Engineer Experience', 'options' => ['Currently assigned/bid Driver Engineer', 'Currently functioning as acting/floating/relief DE', 'Former Driver Engineer', 'DE-certified but not currently functioning as DE', 'No current/former DE experience', 'Prefer not to answer']],
                ['key' => 'service_years', 'label' => 'Years of MBFD/Fire Service', 'options' => ['0–5 years', '6–10 years', '11–15 years', '16–20 years', '21+ years', 'Prefer not to answer']],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function questions(): array
    {
        $agreement = self::scale(['Strongly Agree', 'Agree', 'Neutral', 'Disagree', 'Strongly Disagree'], [5, 4, 3, 2, 1], 2);
        $quality = self::scale(['Excellent', 'Good', 'Fair', 'Poor', 'Very Poor'], [5, 4, 3, 2, 1], 2);

        return [
            self::single(1, 'Overall, how would you rate the current state of Driver Engineer readiness and consistency across MBFD?', self::options([
                ['Excellent — strong and consistent department-wide', 5, true], ['Good — generally strong with some gaps', 4, true], ['Fair — noticeable inconsistencies that should be addressed', 3], ['Poor — significant competency and consistency concerns', 2], ['Very Poor — major operational concerns exist', 1], ['Unsure / Not enough information', null],
            ])),
            self::multi(2, 'Which issues do you believe are currently having the greatest negative impact on the Driver Engineer position?', [
                'No MBFD-specific qualification process after state DE certification', 'Inconsistent knowledge/skill levels among currently assigned Driver Engineers', 'No clearly defined consequences for poor performance or failed remediation', 'Insufficient practical driving/pumping experience before functioning independently', 'Inconsistent company-level training and mentorship', 'Inadequate Driver Engineer knowledge among some company officers', 'Inconsistent fireground tactics or terminology between crews/shifts', 'Limited high-rise/FDC/water-supply repetition', 'Limited training space', 'Insufficient training time / tight schedule', 'Inconsistent evaluator or instructor standards', 'Apparatus placement deficiencies', 'Other',
            ], 3, true),
            self::single(3, 'How strongly do you support requiring an MBFD-specific qualification process before a newly certified member can independently act as or bid a Driver Engineer position?', self::scale(['Strongly Support', 'Support', 'Neutral / Unsure', 'Oppose', 'Strongly Oppose'], [5, 4, 3, 2, 1], 2)),
            self::multi(4, 'If MBFD creates an internal Driver Engineer qualification process, which components should be required?', [
                'MBFD Driver Engineer task book / qualification packet', 'Minimum documented driving experience', 'Minimum documented pumping/water-supply evolutions', 'Station-level mentoring with a qualified DE', 'Apparatus-specific familiarization/sign-offs', 'Standardized practical examination', 'Standardized driving evaluation', 'Short written knowledge examination', 'High-rise/FDC practical', 'Apparatus-placement scenarios', 'Final Training Division sign-off', 'None — state certification should remain sufficient',
            ], null, false, 'None — state certification should remain sufficient'),
            self::single(5, 'Should the same minimum operational competency standard apply to all members functioning as a Driver Engineer, regardless of assignment status?', self::options([
                ['Yes — the same minimum standard should apply to everyone functioning as a DE'], ['Mostly yes — the same core standard, with some differences by assignment'], ['Unsure'], ['No — standards should differ substantially by assignment type'],
            ]), 'This includes bid DEs, floating DEs, acting DEs, overtime fill-ins and temporary assignments.'),
            self::single(6, 'How strongly do you support establishing clearly defined “critical failures” during Driver Engineer evaluations?', self::scale(['Strongly Support', 'Support', 'Neutral / Unsure', 'Oppose', 'Strongly Oppose'], [5, 4, 3, 2, 1], 2), 'Examples include inability to engage the pump, failure to deliver water, failure to recognize a lost water supply, or an unsafe driving action.'),
            self::single(7, 'If a currently assigned Driver Engineer demonstrates a life-safety-critical failure and cannot successfully correct it during the initial training session, which response do you believe is most appropriate?', self::options([
                ['Coaching and immediate repeat attempt only; no further action'], ['Documented remediation while the member remains fully functioning as a DE'], ['Formal remediation with a defined timeframe and reevaluation'], ['Formal remediation with temporary restriction from independent DE duties until competency is demonstrated'], ['Reassignment to a structured days-based remediation program until successfully completed'], ['Immediate administrative review to determine continued DE assignment'], ['Unsure — policy should be developed before evaluations begin'],
            ])),
            self::single(8, 'If a member is unable to successfully complete formal Driver Engineer remediation after multiple reasonable attempts, what should happen?', self::options([
                ['Member should remain in the DE position regardless'], ['Additional remediation should continue indefinitely'], ['Case-by-case review by Training/Operations'], ['Formal review by Administration and Union/Labor representatives'], ['Member should no longer independently function as a DE until competency is demonstrated'], ['Member should lose DE qualification/assignment subject to applicable labor and bid protections'], ['Unsure'],
            ])),
            self::matrix(9, 'Please rate the following statements regarding company officers and the Driver Engineer position.', [
                'Company officers should understand enough Driver Engineer operations to recognize and assist with basic pump/water-supply problems.', 'Company officers should be proficient in MBFD’s current fireground tactics and apparatus-placement expectations.', 'Officer tactical decisions have a direct effect on Driver Engineer performance.', 'Officer and Driver Engineer training should be integrated whenever practical.', 'The Officer Refresher portion of Back to Basics is necessary for improving Driver Engineer operations.',
            ], $agreement),
            self::compound(10, 'How important is it for MBFD to standardize tactical terminology and common “play calls” department-wide?', [
                ['key' => 'importance', 'label' => 'Part A', 'options' => self::scale(['Extremely Important', 'Very Important', 'Moderately Important', 'Slightly Important', 'Not Important'], [5, 4, 3, 2, 1], 2)],
                ['key' => 'approach', 'label' => 'Part B — For terminology such as “skid-load” that is currently interpreted differently, which approach do you prefer?', 'options' => self::options([['Keep the term and establish one official MBFD definition'], ['Replace ambiguous terms with plain-language radio assignments'], ['Use both: standard term followed by a short plain-language clarification'], ['Leave the terminology as it currently exists'], ['Unsure']])],
            ], 'This includes terms such as jump-line, skid-load, forward lay, reverse lay, portable standpipe, high-rise/FDC and water-supply assignments.'),
            self::matrix(11, 'Please rate the current Back to Basics program design as presented during Train-the-Trainer.', ['Overall program concept', 'Five-module progression', 'Driver Engineer content', 'Officer Refresher content', 'Balance of classroom vs. hands-on training', 'Use of scenarios and skill stations', 'Use of actual MBFD apparatus/equipment', 'Ability of the program to address current department needs'], $quality),
            self::single(12, 'Based on what you saw during Train-the-Trainer, how confident are you that the Back to Basics program will improve Driver Engineer competency if implemented as planned?', self::scale(['Very Confident', 'Confident', 'Somewhat Confident', 'Not Very Confident', 'Not Confident at All'], [5, 4, 3, 2, 1], 2)),
            self::matrix(13, 'Please rate the Lead Instructor / Support Services–Logistics team’s performance in developing and leading Back to Basics.', ['Knowledge of the subject matter', 'Preparation and organization', 'Ability to explain the reason behind the training', 'Ability to identify real operational problems', 'Willingness to listen to adjunct instructor feedback', 'Ability to adjust the program based on field input', 'Credibility with instructors/members', 'Overall ability to lead the Back to Basics program'], $quality),
            self::matrix(14, 'Please rate your impression of the equipment selected for the new mid-mount ladder truck.', ['Overall quality of the selected equipment', 'Operational usefulness', 'Suitability for MBFD operations', 'Apparatus/equipment standardization', 'Battery-powered tool strategy', 'Extrication equipment selection', 'Stabilization equipment selection', 'Confidence in the workgroup/equipment-selection process'], array_merge($quality, self::options([['Not Familiar Enough to Rate', null]]))),
            self::multi(15, 'What should MBFD prioritize after completion of the 2026 Back to Basics program?', ['Establish an MBFD Driver Engineer qualification/task-book program', 'Establish standardized DE practical testing', 'Establish a formal remediation policy', 'Establish rules for critical failures and temporary DE restrictions', 'Develop a qualified Acting/Reserve Driver pool', 'Require recurrent DE competency training', 'Expand officer tactics/DE troubleshooting training', 'Standardize fireground terminology/play calls', 'Increase high-rise/FDC training', 'Increase apparatus-placement training', 'Increase company-level DE mentorship', 'Improve target-hazard/preincident familiarization', 'Provide more training time', 'Secure additional training space/resources', 'Continue Back to Basics annually with a different departmental focus each year'], 3, false, null, 3),
        ];
    }

    /** @param array<int,array{0:string,1?:int|null,2?:bool}> $items @return array<int,array<string,mixed>> */
    private static function options(array $items): array
    {
        return array_map(fn (array $item, int $index): array => array_filter(['key' => self::key($item[0], $index), 'label' => $item[0], 'score' => $item[1] ?? null, 'favorable' => $item[2] ?? false], fn ($value) => $value !== null && $value !== false), $items, array_keys($items));
    }

    /** @param array<int,string> $labels @param array<int,int> $scores @return array<int,array<string,mixed>> */
    private static function scale(array $labels, array $scores, int $favorableAtOrAbove): array
    {
        return self::options(array_map(fn (string $label, int $index): array => [$label, $scores[$index], $scores[$index] >= $favorableAtOrAbove && $scores[$index] >= 4], $labels, array_keys($labels)));
    }

    /** @param array<int,array<string,mixed>> $options @return array<string,mixed> */
    private static function single(int $position, string $prompt, array $options, ?string $help = null): array
    {
        return ['position' => $position, 'type' => 'single', 'prompt' => $prompt, 'help_text' => $help, 'is_required' => true, 'configuration' => ['options' => $options]];
    }

    /** @param array<int,string> $options @return array<string,mixed> */
    private static function multi(int $position, string $prompt, array $options, ?int $limit = null, bool $other = false, ?string $exclusive = null, ?int $minimum = null): array
    {
        $exclusiveKey = $exclusive === null ? null : self::key($exclusive, (int) array_search($exclusive, $options, true));

        return ['position' => $position, 'type' => 'multi', 'prompt' => $prompt, 'help_text' => $minimum !== null ? "Select exactly {$minimum}." : ($limit === null ? 'Select all that should be mandatory.' : "Select up to {$limit}."), 'is_required' => true, 'configuration' => ['options' => self::options(array_map(fn (string $value): array => [$value], $options)), 'max_selections' => $limit, 'min_selections' => $minimum, 'allow_other_text' => $other, 'exclusive_option' => $exclusiveKey]];
    }

    /** @param array<int,string> $rows @param array<int,array<string,mixed>> $options @return array<string,mixed> */
    private static function matrix(int $position, string $prompt, array $rows, array $options): array
    {
        return ['position' => $position, 'type' => 'matrix', 'prompt' => $prompt, 'help_text' => 'Rate each statement.', 'is_required' => true, 'configuration' => ['rows' => array_map(fn (string $label, int $index): array => ['key' => "row_{$index}", 'label' => $label], $rows, array_keys($rows)), 'options' => $options]];
    }

    /** @param array<int,array<string,mixed>> $parts @return array<string,mixed> */
    private static function compound(int $position, string $prompt, array $parts, string $help): array
    {
        return ['position' => $position, 'type' => 'compound', 'prompt' => $prompt, 'help_text' => $help, 'is_required' => true, 'configuration' => compact('parts')];
    }

    private static function key(string $label, int $index): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: 'option')), '_')."_{$index}";
    }
}
