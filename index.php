<?php
declare(strict_types=1);

const MEXC_BASE_URL = 'https://api.mexc.com';
const DEFAULT_LOOKBACK = 30;
const DEFAULT_SIGMA = 2.0;
const DEFAULT_INTERVAL = '1d';
const DEFAULT_LIMIT = 120;

final class PairsTradingStrategy
{
    private int $lookbackPeriod;
    private float $numStdDev;

    public function __construct(int $lookbackPeriod = DEFAULT_LOOKBACK, float $numStdDev = DEFAULT_SIGMA)
    {
        if ($lookbackPeriod < 2) {
            throw new InvalidArgumentException('Lookback period must be >= 2.');
        }

        if ($numStdDev <= 0) {
            throw new InvalidArgumentException('Sigma multiplier must be > 0.');
        }

        $this->lookbackPeriod = $lookbackPeriod;
        $this->numStdDev = $numStdDev;
    }

    /**
     * @param array<int, array<string, float|int>> $priceData
     * @return array<int, array<string, mixed>>
     */
    public function calculateSignals(array $priceData): array
    {
        $results = [];
        $positionOpen = false;
        $entryRatio = 0.0;
        $entryTimestamp = 0;
        $positionType = '';

        $count = count($priceData);
        for ($i = $this->lookbackPeriod; $i < $count; $i++) {
            $window = array_slice($priceData, $i - $this->lookbackPeriod, $this->lookbackPeriod);
            $ratios = $this->calculateRatios($window);

            $mean = $this->calculateMean($ratios);
            $stdDev = $this->calculateStandardDeviation($ratios, $mean);

            $currentData = $priceData[$i];
            $currentRatio = (float) $currentData['priceB'] / (float) $currentData['priceA'];

            $upperBand = $mean + ($this->numStdDev * $stdDev);
            $lowerBand = $mean - ($this->numStdDev * $stdDev);
            $upperStopBand = $mean + (3 * $stdDev);
            $lowerStopBand = $mean - (3 * $stdDev);

            $signal = $this->determineSignal(
                $currentRatio,
                $upperBand,
                $lowerBand,
                $positionOpen,
                $positionType,
                $mean
            );

            $tradeResult = $this->handlePosition(
                $signal,
                $positionOpen,
                $entryRatio,
                $entryTimestamp,
                $positionType,
                $currentRatio,
                $upperStopBand,
                $lowerStopBand,
                $currentData
            );

            $positionOpen = $tradeResult['positionOpen'];
            $entryRatio = $tradeResult['entryRatio'];
            $entryTimestamp = $tradeResult['entryTimestamp'];
            $positionType = $tradeResult['positionType'];

            $results[] = [
                'date' => gmdate('Y-m-d', (int) ($currentData['timestamp'] / 1000)),
                'timestamp' => $currentData['timestamp'],
                'priceA' => $currentData['priceA'],
                'priceB' => $currentData['priceB'],
                'ratio' => $currentRatio,
                'mean' => $mean,
                'stdDev' => $stdDev,
                'upperBand' => $upperBand,
                'lowerBand' => $lowerBand,
                'upperStopBand' => $upperStopBand,
                'lowerStopBand' => $lowerStopBand,
                'signal' => $signal,
                'positionOpen' => $positionOpen,
                'positionType' => $positionType,
                'entryRatio' => $entryRatio,
                'pnl' => $tradeResult['pnl'],
                'exitReason' => $tradeResult['exitReason'],
            ];
        }

        return $results;
    }

    /**
     * @param array<int, array<string, float|int>> $window
     * @return array<int, float>
     */
    private function calculateRatios(array $window): array
    {
        return array_map(
            static fn (array $row): float => (float) $row['priceB'] / (float) $row['priceA'],
            $window
        );
    }

    /**
     * @param array<int, float> $values
     */
    private function calculateMean(array $values): float
    {
        return array_sum($values) / max(count($values), 1);
    }

    /**
     * @param array<int, float> $values
     */
    private function calculateStandardDeviation(array $values, float $mean): float
    {
        if (count($values) === 0) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / count($values));
    }

    private function determineSignal(
        float $currentRatio,
        float $upperBand,
        float $lowerBand,
        bool $positionOpen,
        string $positionType,
        float $mean
    ): string {
        if (!$positionOpen) {
            if ($currentRatio >= $upperBand) {
                return 'SELL_B_BUY_A';
            }
            if ($currentRatio <= $lowerBand) {
                return 'BUY_B_SELL_A';
            }
            return 'HOLD';
        }

        if ($positionType === 'SELL_B_BUY_A' && $currentRatio <= $mean) {
            return 'CLOSE_SELL_B_BUY_A';
        }

        if ($positionType === 'BUY_B_SELL_A' && $currentRatio >= $mean) {
            return 'CLOSE_BUY_B_SELL_A';
        }

        return 'HOLD';
    }

    /**
     * @param array<string, float|int> $currentData
     * @return array<string, mixed>
     */
    private function handlePosition(
        string $signal,
        bool $positionOpen,
        float $entryRatio,
        int $entryTimestamp,
        string $positionType,
        float $currentRatio,
        float $upperStopBand,
        float $lowerStopBand,
        array $currentData
    ): array {
        $result = [
            'positionOpen' => $positionOpen,
            'entryRatio' => $entryRatio,
            'entryTimestamp' => $entryTimestamp,
            'positionType' => $positionType,
            'pnl' => 0.0,
            'exitReason' => null,
        ];

        if (!$positionOpen && ($signal === 'SELL_B_BUY_A' || $signal === 'BUY_B_SELL_A')) {
            $result['positionOpen'] = true;
            $result['entryRatio'] = $currentRatio;
            $result['entryTimestamp'] = (int) $currentData['timestamp'];
            $result['positionType'] = $signal;
            return $result;
        }

        if ($positionOpen && ($signal === 'CLOSE_SELL_B_BUY_A' || $signal === 'CLOSE_BUY_B_SELL_A')) {
            $result['pnl'] = $this->calculatePnl($entryRatio, $currentRatio, $positionType);
            $result['positionOpen'] = false;
            $result['entryRatio'] = 0.0;
            $result['entryTimestamp'] = 0;
            $result['positionType'] = '';
            $result['exitReason'] = 'Take Profit';
            return $result;
        }

        if ($positionOpen) {
            if ($positionType === 'SELL_B_BUY_A' && $currentRatio >= $upperStopBand) {
                $result['pnl'] = $this->calculatePnl($entryRatio, $currentRatio, $positionType);
                $result['positionOpen'] = false;
                $result['entryRatio'] = 0.0;
                $result['entryTimestamp'] = 0;
                $result['positionType'] = '';
                $result['exitReason'] = 'Stop Loss';
                return $result;
            }

            if ($positionType === 'BUY_B_SELL_A' && $currentRatio <= $lowerStopBand) {
                $result['pnl'] = $this->calculatePnl($entryRatio, $currentRatio, $positionType);
                $result['positionOpen'] = false;
                $result['entryRatio'] = 0.0;
                $result['entryTimestamp'] = 0;
                $result['positionType'] = '';
                $result['exitReason'] = 'Stop Loss';
                return $result;
            }
        }

        return $result;
    }

    private function calculatePnl(float $entryRatio, float $exitRatio, string $positionType): float
    {
        if ($entryRatio <= 0.0) {
            return 0.0;
        }

        if ($positionType === 'SELL_B_BUY_A') {
            return (($entryRatio - $exitRatio) / $entryRatio) * 100.0;
        }

        if ($positionType === 'BUY_B_SELL_A') {
            return (($exitRatio - $entryRatio) / $entryRatio) * 100.0;
        }

        return 0.0;
    }
}

final class TradingAnalyzer
{
    /**
     * @param array<int, array<string, mixed>> $results
     * @return array<string, mixed>
     */
    public static function analyzeResults(array $results): array
    {
        $analysis = [
            'total_days' => count($results),
            'trades' => [],
            'openPositions' => 0,
            'closedPositions' => 0,
            'wins' => 0,
            'losses' => 0,
            'totalPnl' => 0.0,
        ];

        $currentTrade = null;

        foreach ($results as $row) {
            $signal = $row['signal'];

            if (!$currentTrade && ($signal === 'SELL_B_BUY_A' || $signal === 'BUY_B_SELL_A')) {
                $currentTrade = [
                    'type' => $signal,
                    'entryDate' => $row['date'],
                    'entryRatio' => $row['ratio'],
                    'daysHeld' => 1,
                ];
                $analysis['openPositions']++;
                continue;
            }

            if ($currentTrade) {
                $currentTrade['daysHeld']++;

                if ($row['exitReason'] !== null) {
                    $currentTrade['exitDate'] = $row['date'];
                    $currentTrade['exitRatio'] = $row['ratio'];
                    $currentTrade['exitReason'] = $row['exitReason'];
                    $currentTrade['pnl'] = (float) $row['pnl'];
                    $analysis['trades'][] = $currentTrade;
                    $analysis['closedPositions']++;
                    $analysis['openPositions'] = max(0, $analysis['openPositions'] - 1);
                    $analysis['totalPnl'] += (float) $row['pnl'];
                    if ((float) $row['pnl'] > 0) {
                        $analysis['wins']++;
                    } elseif ((float) $row['pnl'] < 0) {
                        $analysis['losses']++;
                    }
                    $currentTrade = null;
                }
            }
        }

        return $analysis;
    }
}

/**
 * @return array<int, array<string, float|int>>
 */
function fetchKlines(string $symbol, string $interval, int $limit): array
{
    $params = [
        'symbol' => strtoupper($symbol),
        'interval' => $interval,
        'limit' => min(max($limit, 1), 1000),
    ];

    $query = http_build_query($params);
    $url = MEXC_BASE_URL . '/api/v3/klines?' . $query;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error fetching {$symbol}: {$error}");
        }
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $response = @file_get_contents($url);
        $statusCode = $response === false ? 0 : 200;
    }

    if ($response === false) {
        throw new RuntimeException("Failed to fetch klines for {$symbol}");
    }

    if ($statusCode !== 200) {
        throw new RuntimeException("HTTP {$statusCode} while fetching {$symbol}");
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Unexpected response format for {$symbol}");
    }

    $klines = [];
    foreach ($decoded as $row) {
        if (!is_array($row) || count($row) < 5) {
            continue;
        }
        $klines[] = [
            'openTime' => (int) $row[0],
            'close' => (float) $row[4],
        ];
    }

    return $klines;
}

/**
 * @param array<int, array<string, float|int>> $seriesA
 * @param array<int, array<string, float|int>> $seriesB
 * @return array<int, array<string, float|int>>
 */
function alignSeries(array $seriesA, array $seriesB): array
{
    $mapA = [];
    foreach ($seriesA as $row) {
        $mapA[(string) $row['openTime']] = (float) $row['close'];
    }

    $mapB = [];
    foreach ($seriesB as $row) {
        $mapB[(string) $row['openTime']] = (float) $row['close'];
    }

    $commonKeys = array_values(array_intersect(array_keys($mapA), array_keys($mapB)));
    sort($commonKeys);

    $aligned = [];
    foreach ($commonKeys as $key) {
        $timestamp = (int) $key;
        $priceA = $mapA[$key];
        $priceB = $mapB[$key];
        if ($priceA > 0 && $priceB > 0) {
            $aligned[] = [
                'timestamp' => $timestamp,
                'priceA' => $priceA,
                'priceB' => $priceB,
            ];
        }
    }

    return $aligned;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt(float $value, int $decimals = 5): string
{
    return number_format($value, $decimals, '.', '');
}

function fmtPercent(float $value): string
{
    $sign = $value > 0 ? '+' : '';
    return $sign . number_format($value, 2, '.', '') . '%';
}

$symbolA = $_GET['symbolA'] ?? 'BTCUSDT';
$symbolB = $_GET['symbolB'] ?? 'ETHUSDT';
$interval = $_GET['interval'] ?? DEFAULT_INTERVAL;
$lookback = isset($_GET['lookback']) ? max(2, (int) $_GET['lookback']) : DEFAULT_LOOKBACK;
$sigma = isset($_GET['sigma']) ? max(0.1, (float) $_GET['sigma']) : DEFAULT_SIGMA;
$limitParam = isset($_GET['limit']) ? (int) $_GET['limit'] : DEFAULT_LIMIT;
$limit = min(max($limitParam, $lookback + 5), 1000);

$priceData = [];
$message = '';

try {
    $seriesA = fetchKlines($symbolA, $interval, $limit);
    $seriesB = fetchKlines($symbolB, $interval, $limit);
    $priceData = alignSeries($seriesA, $seriesB);
} catch (Throwable $e) {
    $message = 'Ошибка при получении данных из MEXC: ' . $e->getMessage();
}

$results = [];
$analysis = [];

if ($message === '') {
    if (count($priceData) < $lookback + 1) {
        $message = 'Недостаточно общих свечей для расчёта (увеличьте limit или уменьшите lookback).';
    } else {
        try {
            $strategy = new PairsTradingStrategy($lookback, $sigma);
            $results = $strategy->calculateSignals($priceData);
            $analysis = TradingAnalyzer::analyzeResults($results);
        } catch (Throwable $e) {
            $message = 'Ошибка при расчёте стратегии: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Pairs Trading Strategy — MEXC API</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        body {
            margin: 2rem;
            line-height: 1.55;
        }
        h1, h2, h3 {
            margin-bottom: 0.5rem;
        }
        form.controls {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin: 1.5rem 0;
        }
        form.controls label {
            display: flex;
            flex-direction: column;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        form.controls input,
        form.controls select {
            margin-top: 0.25rem;
            padding: 0.4rem 0.5rem;
            min-width: 8rem;
        }
        form.controls button {
            padding: 0.45rem 0.9rem;
            border-radius: 4px;
            border: 1px solid rgba(120, 120, 120, 0.35);
            background: #1068e0;
            color: #fff;
            cursor: pointer;
        }
        .notice {
            padding: 0.8rem 1rem;
            border-radius: 6px;
            border: 1px solid rgba(255, 193, 7, 0.6);
            background: rgba(255, 193, 7, 0.18);
            margin-bottom: 1.5rem;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1.5rem;
        }
        .summary-grid div {
            border: 1px solid rgba(150, 150, 150, 0.35);
            border-radius: 6px;
            padding: 0.75rem;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 2rem;
            font-size: 0.88rem;
        }
        th, td {
            border: 1px solid rgba(150, 150, 150, 0.35);
            padding: 0.45rem 0.6rem;
            text-align: right;
        }
        th {
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.07em;
            background: rgba(120, 120, 120, 0.1);
        }
        td:first-child,
        th:first-child {
            text-align: left;
        }
        tbody tr:nth-child(odd) {
            background: rgba(120, 120, 120, 0.1);
        }
        .signal-entry {
            font-weight: 600;
            color: #be1f1f;
        }
        .signal-exit {
            font-weight: 600;
            color: #0e7d2c;
        }
        .pnl-positive {
            color: #0e7d2c;
        }
        .pnl-negative {
            color: #be1f1f;
        }
        footer {
            margin-top: 3rem;
            font-size: 0.78rem;
            color: rgba(120, 120, 120, 0.85);
        }
    </style>
</head>
<body>
    <h1>Pairs Trading Strategy — Hypothesis 2 (MEXC)</h1>
    <p>
        Расчёт отношения цен <?= h(strtoupper($symbolB)) ?> / <?= h(strtoupper($symbolA)) ?> по свечам MEXC,
        скользящее среднее за <?= h((string) $lookback) ?> периодов и динамические полосы ±<?= h((string) $sigma) ?>σ.
    </p>

    <form class="controls" method="get">
        <label>
            Symbol A (в знаменателе)
            <input type="text" name="symbolA" value="<?= h(strtoupper($symbolA)) ?>" required>
        </label>
        <label>
            Symbol B (в числителе)
            <input type="text" name="symbolB" value="<?= h(strtoupper($symbolB)) ?>" required>
        </label>
        <label>
            Интервал
            <select name="interval">
                <?php
                $intervals = ['1m','5m','15m','1h','4h','1d','1w'];
                foreach ($intervals as $item):
                    $selected = $item === $interval ? 'selected' : '';
                ?>
                    <option value="<?= h($item) ?>" <?= $selected ?>><?= h($item) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Lookback
            <input type="number" name="lookback" min="2" value="<?= h((string) $lookback) ?>">
        </label>
        <label>
            σ множитель
            <input type="number" name="sigma" step="0.1" min="0.1" value="<?= h((string) $sigma) ?>">
        </label>
        <label>
            Limit свечей
            <input type="number" name="limit" min="10" max="1000" value="<?= h((string) $limit) ?>">
        </label>
        <button type="submit">Пересчитать</button>
    </form>

    <?php if ($message !== ''): ?>
        <div class="notice"><?= h($message) ?></div>
    <?php else: ?>
        <section>
            <h2>Сводка</h2>
            <div class="summary-grid">
                <div>
                    <strong>Общие свечи:</strong><br><?= h((string) count($priceData)) ?>
                </div>
                <div>
                    <strong>Учтённых дней:</strong><br><?= h((string) ($analysis['total_days'] ?? 0)) ?>
                </div>
                <div>
                    <strong>Закрытых сделок:</strong><br><?= h((string) ($analysis['closedPositions'] ?? 0)) ?>
                </div>
                <div>
                    <strong>Win / Loss:</strong><br>
                    <?= h((string) ($analysis['wins'] ?? 0)) ?> / <?= h((string) ($analysis['losses'] ?? 0)) ?>
                </div>
                <div>
                    <strong>Совокупный P&amp;L:</strong><br>
                    <span class="<?= ($analysis['totalPnl'] ?? 0) >= 0 ? 'pnl-positive' : 'pnl-negative' ?>">
                        <?= fmtPercent((float) ($analysis['totalPnl'] ?? 0)) ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($analysis['trades'])): ?>
                <h3>Сделки</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Тип</th>
                            <th>Дата входа</th>
                            <th>Дата выхода</th>
                            <th>Периодов</th>
                            <th>P&amp;L</th>
                            <th>Причина</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['trades'] as $trade): ?>
                            <tr>
                                <td><?= h($trade['type']) ?></td>
                                <td><?= h($trade['entryDate']) ?> (<?= fmt((float) $trade['entryRatio']) ?>)</td>
                                <td><?= h($trade['exitDate'] ?? '-') ?> (<?= fmt((float) ($trade['exitRatio'] ?? 0.0)) ?>)</td>
                                <td><?= h((string) ($trade['daysHeld'] ?? 0)) ?></td>
                                <?php $pnl = (float) ($trade['pnl'] ?? 0); ?>
                                <td class="<?= $pnl >= 0 ? 'pnl-positive' : 'pnl-negative' ?>"><?= fmtPercent($pnl) ?></td>
                                <td><?= h($trade['exitReason'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section>
            <h2>Дневные расчёты</h2>
            <table>
                <thead>
                    <tr>
                        <th>Дата (UTC)</th>
                        <th><?= h(strtoupper($symbolA)) ?></th>
                        <th><?= h(strtoupper($symbolB)) ?></th>
                        <th>Ratio</th>
                        <th>Mean</th>
                        <th>σ</th>
                        <th>Верхняя</th>
                        <th>Нижняя</th>
                        <th>Stop+</th>
                        <th>Stop−</th>
                        <th>Сигнал</th>
                        <th>P&amp;L</th>
                        <th>Комментарий</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row): ?>
                        <?php
                            $signalClass = '';
                            if ($row['signal'] === 'SELL_B_BUY_A' || $row['signal'] === 'BUY_B_SELL_A') {
                                $signalClass = 'signal-entry';
                            } elseif (strpos((string) $row['signal'], 'CLOSE_') === 0) {
                                $signalClass = 'signal-exit';
                            }
                            $pnlValue = (float) $row['pnl'];
                            $pnlClass = $pnlValue === 0.0 ? '' : ($pnlValue > 0 ? 'pnl-positive' : 'pnl-negative');
                        ?>
                        <tr>
                            <td><?= h($row['date']) ?></td>
                            <td><?= number_format((float) $row['priceA'], 2, '.', ' ') ?></td>
                            <td><?= number_format((float) $row['priceB'], 2, '.', ' ') ?></td>
                            <td><?= fmt($row['ratio']) ?></td>
                            <td><?= fmt($row['mean']) ?></td>
                            <td><?= fmt($row['stdDev'], 6) ?></td>
                            <td><?= fmt($row['upperBand']) ?></td>
                            <td><?= fmt($row['lowerBand']) ?></td>
                            <td><?= fmt($row['upperStopBand']) ?></td>
                            <td><?= fmt($row['lowerStopBand']) ?></td>
                            <td class="<?= $signalClass ?>"><?= h($row['signal']) ?></td>
                            <td class="<?= $pnlClass ?>"><?= $pnlValue !== 0.0 ? fmtPercent($pnlValue) : '-' ?></td>
                            <td><?= h($row['exitReason'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <footer>
        <p>
            Данные загружаются с публичного REST API MEXC (`/api/v3/klines`). Можно менять символы —
            например, знаменатель `BTCUSDT`, числитель `ETHUSDT`, чтобы получить отношение ETH/BTC.
        </p>
    </footer>
</body>
</html>

