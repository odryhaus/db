<?php

function client_health_month_end_reference(string $selectedMonth): DateTimeImmutable
{
    $monthDate = DateTimeImmutable::createFromFormat('!Y-m', cockpit_valid_month($selectedMonth)) ?: new DateTimeImmutable('first day of this month');
    $monthEnd = $monthDate->modify('last day of this month')->setTime(23, 59, 59);
    $today = new DateTimeImmutable('today');

    return $monthEnd > $today ? $today : $monthEnd;
}

function client_health_parse_order_dates(string $orderDates): array
{
    $dates = [];
    foreach (explode('|||', $orderDates) as $date) {
        $date = trim($date);
        if ($date === '') {
            continue;
        }
        $time = strtotime($date);
        if ($time !== false) {
            $dates[] = date('Y-m-d', $time);
        }
    }

    return array_values(array_unique($dates));
}

function client_health_median_interval_days(array $orderDates): ?int
{
    sort($orderDates);
    if (count($orderDates) < 3) {
        return null;
    }

    $intervals = [];
    for ($i = 1; $i < count($orderDates); $i++) {
        $previous = strtotime((string) $orderDates[$i - 1]);
        $current = strtotime((string) $orderDates[$i]);
        if ($previous !== false && $current !== false && $current > $previous) {
            $intervals[] = max(1, (int) floor(($current - $previous) / 86400));
        }
    }
    if (count($intervals) < 2) {
        return null;
    }

    sort($intervals);
    $middle = intdiv(count($intervals), 2);
    if (count($intervals) % 2 === 1) {
        return $intervals[$middle];
    }

    return (int) round(($intervals[$middle - 1] + $intervals[$middle]) / 2);
}

function client_health_cycle_signal(array $orderDates, string $selectedMonth, string $trendClass): array
{
    sort($orderDates);
    $reference = client_health_month_end_reference($selectedMonth);
    $lastOrderDate = $orderDates ? (string) end($orderDates) : '';
    $daysSinceLast = null;
    if ($lastOrderDate !== '') {
        $last = DateTimeImmutable::createFromFormat('!Y-m-d', $lastOrderDate);
        if ($last instanceof DateTimeImmutable) {
            $daysSinceLast = max(0, (int) $last->diff($reference)->format('%r%a'));
        }
    }

    $medianCycle = client_health_median_interval_days($orderDates);
    $penalty = 0;
    $label = 'цикл невідомий';
    $detail = 'недостатньо історії для індивідуального циклу';

    if ($medianCycle !== null && $daysSinceLast !== null) {
        $label = $medianCycle . ' дн.';
        $overDays = $daysSinceLast - $medianCycle;
        if ($overDays <= 0) {
            $detail = 'у звичному циклі';
        } else {
            $ratio = $overDays / max(1, $medianCycle);
            if ($ratio <= 0.25) {
                $penalty = 5;
            } elseif ($ratio <= 0.5) {
                $penalty = 12;
            } elseif ($ratio <= 1) {
                $penalty = 22;
            } else {
                $penalty = 35;
            }
            $detail = 'цикл перевищено на ' . $overDays . ' дн.';
        }
    } elseif ($trendClass === 'sleeping') {
        $penalty = 20;
        $detail = 'немає достатньої історії циклу, але клієнт спить';
    } elseif ($trendClass === 'down') {
        $penalty = 10;
        $detail = 'немає достатньої історії циклу, але є падіння активності';
    }

    return [
        'penalty' => $penalty,
        'median_cycle_days' => $medianCycle,
        'cycle_label' => $label,
        'deviation_label' => $detail,
        'days_since_last_order' => $daysSinceLast,
    ];
}

function client_health_payment_signal(float $receivableTotal, ?string $paymentDueDate): array
{
    if ($receivableTotal <= 0) {
        return [
            'penalty' => 0,
            'status' => 'Оплачено',
            'detail' => '',
            'days_overdue' => 0,
            'due_date' => null,
        ];
    }
    if ($paymentDueDate === null || $paymentDueDate === '') {
        return [
            'penalty' => 0,
            'status' => 'Строк не визначено',
            'detail' => 'борг не штрафується без payment_due_date',
            'days_overdue' => null,
            'due_date' => null,
        ];
    }

    $due = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDueDate);
    if (!$due) {
        return [
            'penalty' => 0,
            'status' => 'Строк не визначено',
            'detail' => 'payment_due_date має неочікуваний формат',
            'days_overdue' => null,
            'due_date' => null,
        ];
    }

    $today = new DateTimeImmutable('today');
    $days = (int) $due->diff($today)->format('%r%a');
    if ($days < 0) {
        return [
            'penalty' => 0,
            'status' => 'У межах погодженого строку',
            'detail' => 'оплата до ' . $due->format('d.m.Y'),
            'days_overdue' => 0,
            'due_date' => $due->format('Y-m-d'),
        ];
    }
    if ($days === 0) {
        return [
            'penalty' => 0,
            'status' => 'Строк сьогодні',
            'detail' => 'оплата сьогодні',
            'days_overdue' => 0,
            'due_date' => $due->format('Y-m-d'),
        ];
    }

    if ($days <= 7) {
        $penalty = 5;
        $status = 'Прострочено 1-7 днів';
    } elseif ($days <= 14) {
        $penalty = 10;
        $status = 'Прострочено 8-14 днів';
    } elseif ($days <= 30) {
        $penalty = 18;
        $status = 'Прострочено 15-30 днів';
    } else {
        $penalty = 25;
        $status = 'Прострочено понад 30 днів';
    }

    return [
        'penalty' => min(30, $penalty),
        'status' => $status,
        'detail' => 'прострочено ' . $days . ' дн.',
        'days_overdue' => $days,
        'due_date' => $due->format('Y-m-d'),
    ];
}

function client_health_trend_signal(string $trendClass): array
{
    if (in_array($trendClass, ['up', 'returned'], true)) {
        return ['penalty' => 0, 'bonus' => 5, 'reason' => 'позитивна динаміка'];
    }
    if ($trendClass === 'down') {
        return ['penalty' => 10, 'bonus' => 0, 'reason' => 'падає'];
    }
    if ($trendClass === 'sleeping') {
        return ['penalty' => 20, 'bonus' => 0, 'reason' => 'спить'];
    }
    return ['penalty' => 0, 'bonus' => 0, 'reason' => ''];
}

function client_health_label(int $score): array
{
    if ($score >= 80) {
        return ['class' => 'healthy', 'label' => 'здорові відносини'];
    }
    if ($score >= 60) {
        return ['class' => 'watch', 'label' => 'потребує уваги'];
    }
    if ($score >= 40) {
        return ['class' => 'risk', 'label' => 'ризик'];
    }
    return ['class' => 'critical', 'label' => 'критичний стан'];
}

function client_health_churn_risk(array $cycleSignal, string $trendClass): array
{
    $cyclePenalty = (int) ($cycleSignal['penalty'] ?? 0);
    if ($trendClass === 'sleeping' || $cyclePenalty >= 35) {
        return ['class' => 'critical', 'label' => 'критичний'];
    }
    if ($trendClass === 'down' || $cyclePenalty >= 22) {
        return ['class' => 'high', 'label' => 'високий'];
    }
    if ($cyclePenalty >= 12) {
        return ['class' => 'medium', 'label' => 'середній'];
    }
    return ['class' => 'low', 'label' => 'низький'];
}

function client_health_work_priority(array $valueSegment, array $churnRisk, array $paymentSignal, string $trendClass): array
{
    $isImportant = in_array((string) ($valueSegment['class'] ?? ''), ['vip', 'large'], true);
    $paymentPenalty = (int) ($paymentSignal['penalty'] ?? 0);
    $riskClass = (string) ($churnRisk['class'] ?? 'low');

    if (($isImportant && in_array($riskClass, ['high', 'critical'], true)) || ($isImportant && $paymentPenalty >= 10)) {
        return ['class' => 'high', 'label' => 'високий', 'action' => 'менеджеру взяти в роботу цього тижня'];
    }
    if ($riskClass === 'critical' || $paymentPenalty >= 18 || $trendClass === 'sleeping') {
        return ['class' => 'high', 'label' => 'високий', 'action' => 'зафіксувати причину паузи і план повернення'];
    }
    if (in_array($riskClass, ['medium', 'high'], true) || $trendClass === 'down') {
        return ['class' => 'medium', 'label' => 'середній', 'action' => 'перевірити клієнта і поставити next follow-up'];
    }
    return ['class' => 'low', 'label' => 'низький', 'action' => 'підтримувати регулярний контакт'];
}

function client_health_calculate(array $input): array
{
    $trendClass = (string) ($input['trend_class'] ?? 'idle');
    $valueSegment = is_array($input['value_segment'] ?? null) ? $input['value_segment'] : ['class' => 'none', 'label' => 'без закупок'];
    $cycleSignal = client_health_cycle_signal(
        is_array($input['order_dates'] ?? null) ? $input['order_dates'] : [],
        (string) ($input['selected_month'] ?? date('Y-m')),
        $trendClass
    );
    $paymentSignal = client_health_payment_signal(
        (float) ($input['receivable_total'] ?? 0),
        ($input['payment_due_date'] ?? null) !== '' ? ($input['payment_due_date'] ?? null) : null
    );
    $trendSignal = client_health_trend_signal($trendClass);

    $penalties = (int) $cycleSignal['penalty'] + (int) $paymentSignal['penalty'] + (int) $trendSignal['penalty'];
    $score = max(0, min(100, 100 - $penalties + (int) $trendSignal['bonus']));
    $label = client_health_label($score);
    $churnRisk = client_health_churn_risk($cycleSignal, $trendClass);
    $priority = client_health_work_priority($valueSegment, $churnRisk, $paymentSignal, $trendClass);

    $reasons = [];
    if ((int) $cycleSignal['penalty'] > 0) {
        $reasons[] = (string) $cycleSignal['deviation_label'];
    }
    if ((string) $trendSignal['reason'] !== '') {
        $reasons[] = (string) $trendSignal['reason'];
    }
    if ((int) $paymentSignal['penalty'] > 0) {
        $reasons[] = (string) $paymentSignal['detail'];
    } elseif ((float) ($input['receivable_total'] ?? 0) > 0) {
        $reasons[] = (string) $paymentSignal['status'] . ' - без штрафу';
    }
    if (!$reasons) {
        $reasons[] = 'критичних сигналів немає';
    }

    return [
        'score' => $score,
        'class' => $label['class'],
        'label' => $label['label'],
        'cycle' => $cycleSignal,
        'payment' => $paymentSignal,
        'churn_risk' => $churnRisk,
        'priority' => $priority,
        'reasons' => array_slice($reasons, 0, 4),
    ];
}
