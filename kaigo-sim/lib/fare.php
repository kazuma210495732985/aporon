<?php
declare(strict_types=1);

/**
 * 片道の距離(m)から運賃を出す。料金表の範囲外なら null。
 */
function fare_for_distance(array $s, int $meters): ?int
{
    if ($s['fare_mode'] === 'table') {
        $rows = $s['fare_table'];
        usort($rows, fn($a, $b) => $a['up_to_km'] <=> $b['up_to_km']);
        foreach ($rows as $row) {
            if ($meters <= $row['up_to_km'] * 1000) {
                return (int)$row['fare'];
            }
        }
        return null;
    }

    $baseM = (int)round($s['base_km'] * 1000);
    $addM = max(1, (int)round($s['add_km'] * 1000));
    if ($meters <= $baseM) {
        return (int)$s['base_fare'];
    }
    // 加算距離に達するごとに加算（メーターと同じく端数距離も1単位として数える）
    return (int)$s['base_fare'] + (int)ceil(($meters - $baseM) / $addM) * (int)$s['add_fare'];
}

function round_amount(int $amount, int $unit, string $method): int
{
    if ($unit <= 1) {
        return $amount;
    }
    $f = match ($method) {
        'floor' => 'floor',
        'round' => 'round',
        default => 'ceil',
    };
    return (int)($f($amount / $unit) * $unit);
}

/**
 * 見積もりを作る。
 * @param string[] $optionNames 選択されたオプション名
 */
function calc_quote(array $s, int $meters, bool $roundTrip, array $optionNames): array
{
    $km = $meters / 1000;
    $kmLabel = number_format($km, 1);
    $roundTrip = $roundTrip && $s['round_trip_enabled'];

    $fare = fare_for_distance($s, $meters);
    $outOfRange = $fare === null || ($s['max_km'] > 0 && $km > $s['max_km']);
    if ($outOfRange) {
        return [
            'out_of_range' => true,
            'distance_km' => round($km, 1),
            'round_trip' => $roundTrip,
            'items' => [],
            'options' => [],
            'total' => null,
        ];
    }

    $items = [];
    $items[] = $roundTrip
        ? ['label' => "運賃（片道 約{$kmLabel}km × 往復）", 'amount' => $fare * 2]
        : ['label' => "運賃（約{$kmLabel}km）", 'amount' => $fare];

    if ((int)$s['pickup_fee'] > 0) {
        $items[] = ['label' => '迎車料金', 'amount' => (int)$s['pickup_fee']];
    }

    // オプションは名前で照合（管理画面で並び替えても選択がずれないように）
    $selected = [];
    foreach ($s['options'] as $opt) {
        if (in_array($opt['name'], $optionNames, true)) {
            $selected[] = $opt['name'];
            $items[] = ['label' => $opt['name'], 'amount' => (int)$opt['price']];
        }
    }

    $subtotal = array_sum(array_column($items, 'amount'));
    $total = round_amount($subtotal, (int)$s['rounding_unit'], (string)$s['rounding_method']);
    if ($total !== $subtotal) {
        $items[] = ['label' => '端数調整', 'amount' => $total - $subtotal];
    }

    return [
        'out_of_range' => false,
        'distance_km' => round($km, 1),
        'round_trip' => $roundTrip,
        'items' => $items,
        'options' => $selected,
        'total' => $total,
    ];
}
