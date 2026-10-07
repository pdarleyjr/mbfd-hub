<?php

declare(strict_types=1);

namespace App\Services\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Enums\PersonnelRequestType;
use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\PersonnelRequestItem;
use App\Models\Uniform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UniformEntitlementService
{
    public function __construct(private readonly UniformOrderCatalog $catalog) {}

    /** Jacket styles share one allowance, measured from an actual recorded issue. */
    public function jacketEligibility(Employee $employee): array
    {
        // Check outstanding requests before issues so a concurrently completed issue
        // cannot disappear between checking its request and checking its issue date.
        $pending = $employee->personnelRequests()->where('type', PersonnelRequestType::Uniform)
            ->whereNotIn('status', [PersonnelRequestStatus::Denied, PersonnelRequestStatus::Cancelled])
            ->whereHas('items', fn (Builder $query) => $query->where('item_code', 'jacket'))
            ->where(fn (Builder $query) => $query->where('status', '!=', PersonnelRequestStatus::Completed)
                ->orWhereHas('items', fn (Builder $items) => $items->where('item_code', 'jacket')
                    ->where(fn (Builder $unfulfilled) => $unfulfilled->where('fulfillment_status', '!=', 'fulfilled')
                        ->orWhereColumn('fulfilled_quantity', '<', 'quantity'))))
            ->exists();
        $lastIssue = $this->lastJacketIssue($employee);
        $issuedAt = $lastIssue?->issued_at;
        $eligibleFrom = $issuedAt?->copy()->addYearsNoOverflow(3);
        $recentIssue = $eligibleFrom !== null && today()->lt($eligibleFrom);
        $reason = $pending ? 'You already have a jacket request awaiting issue. View it in My Requests.'
            : ($recentIssue ? 'Your last jacket was issued on '.$issuedAt->format('M j, Y').'. You may request one again from '.$eligibleFrom->format('M j, Y').'.'
                : ($issuedAt ? 'You may request one jacket. All three styles share the same 3-year allowance.'
                    : 'You may request one jacket. Support Services will confirm any earlier off-system issue history.'));

        return [
            'can_order' => ! $pending && ! $recentIssue,
            'reason' => $reason,
            'last_issued_at' => $issuedAt?->toDateString(),
            'eligible_from' => $eligibleFrom?->toDateString(),
        ];
    }

    public function isJacketStock(Uniform $uniform, ?PersonnelRequestItem $sourceItem = null): bool
    {
        return $sourceItem?->item_code === 'jacket' || in_array($uniform->item_name, $this->jacketStockLabels(), true);
    }

    /** Issuance ignores pending holds; an actual issue starts the shared cycle. */
    public function assertJacketIssueAllowed(Employee|User $recipient, int $quantity, string $issuedAt): void
    {
        $recipient = $this->lockIssueRecipient($recipient);
        if ($quantity !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Issue only one jacket. All three styles share one allowance every 3 years.']);
        }
        Validator::make(['issued_at' => $issuedAt], ['issued_at' => ['required', 'date', 'before_or_equal:today']])->validate();
        $lastIssue = $this->lastJacketIssue($recipient);
        $eligibleFrom = $lastIssue?->issued_at?->copy()->addYearsNoOverflow(3);
        if ($eligibleFrom !== null && Carbon::parse($issuedAt)->startOfDay()->lt($eligibleFrom)) {
            throw ValidationException::withMessages(['issued_at' => 'The next jacket may be issued from '.$eligibleFrom->format('M j, Y').'. The 3-year cycle starts when issued.']);
        }
    }

    public function lockIssueRecipient(Employee|User $recipient): Employee|User
    {
        if ($recipient instanceof User) {
            $user = User::query()->lockForUpdate()->findOrFail($recipient->id);
            if ($user->employee_profile_id === null) {
                return $user;
            }
            $employee = Employee::query()->whereKey($user->employee_profile_id)->where('employee_id', $user->employee_id)->lockForUpdate()->first();
        } else {
            $user = User::query()->where('employee_profile_id', $recipient->id)->lockForUpdate()->first();
            $employee = Employee::query()->lockForUpdate()->findOrFail($recipient->id);
            if ($user && $user->employee_id !== $employee->employee_id) {
                $employee = null;
            }
        }
        if ($employee === null) {
            throw ValidationException::withMessages(['employee' => 'Confirm the existing canonical personnel link before issuing inventory.']);
        }

        return $employee;
    }

    private function jacketStockLabels(): array
    {
        return array_unique(array_merge([
            $this->catalog->product('jacket')['label'],
            config('personnel_requests.uniform_catalog.jacket.label'),
        ], config('uniform_orders.legacy_jacket_stock_labels', []), array_column(config('uniform_orders.jacket_styles'), 'label')));
    }

    private function lastJacketIssue(Employee|User $recipient): ?AssignedEquipment
    {
        $names = $this->jacketStockLabels();
        $query = AssignedEquipment::query()->whereNotNull('issued_at');
        if ($recipient instanceof User) {
            $query->where('user_id', $recipient->id);
        } else {
            $query->where(fn (Builder $owned) => $owned->where('employee_portal_id', $recipient->id)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('employee_portal_id')
                    ->whereHas('user', fn (Builder $users) => $users->where('employee_profile_id', $recipient->id)->where('employee_id', $recipient->employee_id))));
        }

        return $query->where(fn (Builder $match) => $match->whereHas('sourcePersonnelRequestItem', fn (Builder $items) => $items->where('item_code', 'jacket'))
            ->orWhereHas('uniform', fn (Builder $uniforms) => $uniforms->whereIn('item_name', $names))
            ->orWhere(fn (Builder $legacy) => $legacy->whereNull('source_personnel_request_item_id')->where('category', 'Jacket')))
            ->orderByDesc('issued_at')->first();
    }

    public function validateJacketQuantity(array $items): void
    {
        $total = 0;
        foreach ($items as $index => $item) {
            if (! is_array($item) || ($item['item_code'] ?? null) !== 'jacket') {
                continue;
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (is_bool($item['quantity'] ?? null) || $quantity !== 1 || ++$total > 1) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => 'Choose only one jacket. All three styles share one allowance every 3 years.']);
            }
        }
    }

    public function forEmployee(Employee $employee): array
    {
        $assignment = $employee->bidAssignments()->whereNull('superseded_at')
            ->orderByDesc('bid_year')->orderByDesc('id')->first();
        $snapshot = $assignment?->only([
            'id', 'bid_year', 'term_label', 'division_label', 'unit_label', 'position_label',
            'bid_selection_label', 'assignment_type', 'assignment_source', 'station_label',
            'rank_label', 'shift_label', 'a_day_code', 'a_day_label',
        ]);
        $classification = $this->classify($snapshot ?? []);
        $profile = config('uniform_orders.profiles.'.$classification['profile'], []);
        $allowances = $profile['allowances'] ?? [];
        if ($classification['marine']) {
            $allowances += config('uniform_orders.marine_allowances', []);
        }
        $rank = (string) $employee->rank;
        $rankRules = config('uniform_orders.rank_recommendations');

        return $classification + [
            'profile_label' => $profile['label'],
            'assignment_snapshot' => $snapshot,
            'assignment_label' => $snapshot['bid_selection_label'] ?? $snapshot['unit_label'] ?? 'Day / Other assignment',
            'bid_year' => $snapshot['bid_year'] ?? null,
            'term_label' => $snapshot['term_label'] ?? null,
            'rank' => $rank,
            'allowances' => $allowances,
            'message' => $profile['message'] ?? null,
            'three_year_message' => config('uniform_orders.three_year_message'),
            'recommended_variants' => ['class_a_pants' => ['color' => preg_match($rankRules['chief_pattern'], $rank)
                ? $rankRules['chief_pants_color'] : $rankRules['default_pants_color']]],
        ];
    }

    /** Classify structured bid fields; Marine is an additional allowance. */
    public function classify(array $assignment): array
    {
        $rules = config('uniform_orders.assignment_matching');
        $operational = implode(' ', array_map(
            fn (string $field): string => (string) ($assignment[$field] ?? ''),
            ['division_label', 'unit_label', 'position_label', 'bid_selection_label', 'assignment_type'],
        ));
        $marine = (bool) preg_match($rules['marine'], $operational);
        $profile = 'day_other';
        // A chief/prevention/day position is not made operational by a broad division label.
        if (! preg_match($rules['day_other'], $operational.' '.($assignment['rank_label'] ?? ''))) {
            if (preg_match($rules['rescue'], $operational)) {
                $profile = 'rescue';
            } elseif (preg_match($rules['combat'], $operational)) {
                $profile = 'combat';
            }
        }

        return ['profile' => $profile, 'marine' => $marine];
    }

    /** Allocation arithmetic is advisory and does not validate input or block requests. */
    public function summarize(array $context, array $items): array
    {
        $products = $this->catalog->products();
        $quantities = array_fill_keys(array_keys($this->catalog->groupLabels()), 0);
        $selectedTotal = 0;
        foreach ($items as $item) {
            $product = $products[$item['item_code'] ?? ''] ?? null;
            if ($product === null) {
                continue;
            }
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $quantities[$product['group']] += $quantity;
            $selectedTotal += $quantity;
        }

        $allowed = $context['allowances'];
        $swapRule = config('uniform_orders.swap');
        $swaps = isset($allowed[$swapRule['from']])
            ? max(0, $allowed[$swapRule['from']] - $quantities[$swapRule['from']]) * $swapRule['rate'] : 0;
        foreach ($swapRule['to'] as $group) {
            if (isset($allowed[$group])) {
                $allowed[$group] += $swaps;
            }
        }
        if (isset($allowed['work_sets'])) {
            $allowed['work_sets'] += $swaps;
        }
        $warnings = [];
        foreach ($quantities as $group => $quantity) {
            if (isset($allowed[$group]) && $quantity > $allowed[$group]) {
                $warnings[] = $this->catalog->groupLabels()[$group].": {$quantity} selected; standard allowance {$allowed[$group]}. Support Services may review this exception.";
            }
            if ($quantity > 0 && str_starts_with($group, 'marine_') && ! $context['marine']) {
                $warnings[] = $this->catalog->groupLabels()[$group].' are outside your standard assignment allocation. You may still submit them for Support Services review.';
            }
        }
        if ($quantities['polos'] !== $quantities['pants']) {
            $warnings[] = 'Polo and pant quantities differ. A work set is one polo plus one pair of pants; you may still submit individual replacements.';
        }
        if ($quantities['class_a_coats'] > 0) {
            $warnings[] = 'Class A coats are available for Support Services review; a separate standard quantity is not configured.';
        }
        if ($quantities['uniform_shirts'] > 0) {
            $warnings[] = 'The existing Uniform Shirt item remains available for Support Services review. Only polos count toward the standard work-set allowance.';
        }
        $periodic = config('uniform_orders.three_year_allowances');
        if ($quantities['jackets'] > 0 || $quantities['raincoats'] > 0) {
            $warnings[] = config('uniform_orders.three_year_message');
            foreach ($periodic as $group => $quantity) {
                if ($quantities[$group] > $quantity) {
                    $warnings[] = $this->catalog->groupLabels()[$group].": {$quantities[$group]} selected; the 3-year provision is {$quantity}. Support Services may review this exception.";
                }
            }
        }

        return [
            'quantities' => $quantities,
            'allowed' => $allowed,
            'swaps' => $swaps,
            'warnings' => $warnings,
            'selected_total' => $selectedTotal,
            'work_sets' => ['selected' => min($quantities['polos'], $quantities['pants']), 'allowed' => $allowed['work_sets'] ?? null,
                'polos' => $quantities['polos'], 'pants' => $quantities['pants']],
            'pricing_available' => false,
            'order_value' => null,
        ];
    }

    /** Store the assignment and policy used for this request, independently of later bids. */
    public function snapshot(array $context, array $summary, ?string $note = null): array
    {
        $note = trim($note ?? '');
        if (mb_strlen($note) > config('uniform_orders.note_max')) {
            throw ValidationException::withMessages(['member_note' => 'Keep your note to '.config('uniform_orders.note_max').' characters or fewer.']);
        }

        return [
            'bid_assignment_snapshot' => $context['assignment_snapshot'],
            'bid_year' => $context['bid_year'],
            'term_label' => $context['term_label'],
            'assignment_label' => $context['assignment_label'],
            'entitlement_profile' => $context['profile'],
            'entitlement_profile_label' => $context['profile_label'],
            'marine_entitlement' => $context['marine'],
            'entitlement_version' => config('uniform_orders.version'),
            'standard_allowances' => $context['allowances'],
            'calculated_swap_credits' => $summary['swaps'],
            'member_note' => $note === '' ? null : $note,
            'warnings_at_submission' => $summary['warnings'],
            'form_version' => config('uniform_orders.form_version'),
        ];
    }

    /** Validate structured inputs and derive the legacy size string on the server. */
    public function normalizeItems(array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Select at least one uniform item.']);
        }
        $this->validateJacketQuantity($items);
        $errors = [];
        $normalized = [];
        foreach ($items as $index => $item) {
            $path = "items.{$index}";
            if (! is_array($item)) {
                $errors[$path] = 'Select a valid uniform item.';

                continue;
            }
            $code = is_string($item['item_code'] ?? null) ? $item['item_code'] : '';
            $product = $this->catalog->product($code);
            if ($product === null) {
                $errors[$path.'.item_code'] = 'This item is not available through uniform self-service.';

                continue;
            }
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            $quantityMax = $product['quantity_max'] ?? config('uniform_orders.quantity_max');
            if (is_bool($item['quantity'] ?? null) || $quantity === false || $quantity < 1 || $quantity > $quantityMax) {
                $errors[$path.'.quantity'] = 'Enter a whole quantity between 1 and '.$quantityMax.'.';
            }
            $attributes = $item['metadata'] ?? $item['attributes'] ?? [];
            if (! is_array($attributes)) {
                $errors[$path.'.metadata'] = 'Enter valid ordering details.';

                continue;
            }
            $metadata = $product['variant'];
            foreach ($product['fields'] as $field) {
                $key = $field['key'];
                $value = $attributes[$key] ?? null;
                if ($value === null || $value === '') {
                    if ($field['required'] ?? false) {
                        $errors[$path.'.metadata.'.$key] = 'Enter '.$field['label'].'.';
                    }

                    continue;
                }
                $valid = is_scalar($value) && ! is_bool($value);
                if ($field['type'] === 'select') {
                    $valid = $valid && array_key_exists((string) $value, $field['options']);
                } elseif ($field['type'] === 'number') {
                    $valid = $valid && is_numeric($value) && is_finite((float) $value)
                        && (float) $value >= $field['min'] && (float) $value <= $field['max'];
                    if ($valid) {
                        $steps = ((float) $value - $field['min']) / $field['step'];
                        $valid = abs($steps - round($steps)) < 0.000001;
                    }
                } else {
                    $valid = $valid && trim((string) $value) !== '' && mb_strlen(trim((string) $value)) <= ($field['max_length'] ?? 30);
                }
                if (! $valid) {
                    $errors[$path.'.metadata.'.$key] = 'Enter a valid '.$field['label'].'.';

                    continue;
                }
                $metadata[$key] = $field['type'] === 'number' ? (float) $value : trim((string) $value);
            }
            $normalized[] = [
                'item_code' => $code,
                'item_name' => $code === 'jacket' && isset($metadata['jacket_style'])
                    ? config('uniform_orders.jacket_styles.'.$metadata['jacket_style'].'.label') : $product['label'],
                'category' => 'uniform',
                'quantity' => $quantity,
                'size' => $this->canonicalSize($metadata, $product),
                'metadata' => $metadata,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    private function canonicalSize(array $metadata, array $product): string
    {
        $number = fn (string $key): string => isset($metadata[$key]) ? (string) (float) $metadata[$key] : '';
        if (isset($metadata['waist'])) {
            $size = $number('waist').'W'.(isset($metadata['inseam']) ? ' × '.$number('inseam').'L' : '');
        } elseif (isset($metadata['neck'])) {
            $size = 'Neck '.$number('neck').(isset($metadata['sleeve_length']) ? ' / Sleeve '.$number('sleeve_length') : '');
        } elseif (isset($metadata['jumpsuit_chest'])) {
            $size = 'Chest '.$number('jumpsuit_chest').' / '.($metadata['jumpsuit_length'] ?? '');
        } elseif (isset($metadata['shoe_size'])) {
            $size = 'US '.$number('shoe_size');
            if (isset($metadata['footwear_type'])) {
                $type = collect($product['fields'])->firstWhere('key', 'footwear_type');
                $size = $type['options'][$metadata['footwear_type']].' / '.$size;
            }
        } else {
            $size = $metadata['size'] ?? 'Standard';
        }
        if (isset($metadata['cut'])) {
            $cut = collect($product['fields'])->firstWhere('key', 'cut');
            $size .= ' / '.$cut['options'][$metadata['cut']];
        }
        if (isset($metadata['color'])) {
            $color = collect($product['fields'])->firstWhere('key', 'color');
            $size .= ' / '.$color['options'][$metadata['color']];
        }
        if (isset($metadata['width'])) {
            $size .= ' / Width '.$metadata['width'];
        }

        return $size;
    }
}
