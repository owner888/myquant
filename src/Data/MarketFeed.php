<?php
declare(strict_types=1);

namespace MyQuant\Data;

use MyPHP\{URLRequest, URLSession};
use RuntimeException;
use ZipArchive;

/**
 * Unified Dual-Market DataFeed (Option C):
 *  - `crypto` (Binance Spot/Perp: BTCUSDT, ETHUSDT, SOLUSDT, PAXGUSDT)
 *  - `forex`  (Exness Zero Gold/Forex: XAUUSDz, EURUSDz, USDJPYz — supports
 *              local Exness Tick/OHLC CSV & ZIP import + live PAXG/spot sync)
 */
final class MarketFeed
{
    public const MARKETS = [
        'crypto' => [
            'label' => '加密市场 · Binance',
            'symbols' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'PAXGUSDT'],
            'defaultFeeBps' => 8.0, // 0.08% per leg (Taker + slippage)
        ],
        'forex' => [
            'label' => '黄金外汇 · Exness Zero',
            'symbols' => ['XAUUSDz', 'EURUSDz', 'USDJPYz'],
            'defaultFeeBps' => 0.2, // 0.002% per leg ($0.08/oz commission on $4,400 gold)
        ],
    ];

    public const INTERVALS = ['5m', '15m', '1h', '4h', '1d'];

    public static function intervalMs(string $interval): int
    {
        return match ($interval) {
            '1m' => 60_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '1h' => 3_600_000,
            '4h' => 14_400_000,
            '1d' => 86_400_000,
            default => 3_600_000,
        };
    }

    /**
     * Fetches up to 1000 recent candles online (Binance REST for crypto & XAUUSDz via PAXGUSDT spot gold proxy).
     *
     * @return list<Candle>
     */
    public static function fetchOnline(string $market, string $symbol, string $interval, int $limit = 800): array
    {
        $binanceSymbol = match ($symbol) {
            'XAUUSDz' => 'PAXGUSDT',
            'EURUSDz' => 'EURUSDT',
            default => $symbol,
        };
        if ($symbol === 'USDJPYz') {
            return self::syntheticDataset($market, $symbol, $interval, $limit);
        }
        $url = sprintf(
            'https://api.binance.com/api/v3/klines?symbol=%s&interval=%s&limit=%d',
            rawurlencode($binanceSymbol),
            rawurlencode($interval),
            min(1000, max(60, $limit))
        );
        [$body, $resp] = URLSession::shared()->data(for: new URLRequest($url));
        if ($resp->statusCode < 200 || $resp->statusCode >= 300) {
            throw new RuntimeException("HTTP {$resp->statusCode} fetching $symbol ($interval)");
        }
        $raw = json_decode($body, true);
        if (!is_array($raw) || $raw === []) {
            throw new RuntimeException("Empty candle payload for $symbol");
        }
        $candles = [];
        foreach ($raw as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $candles[] = new Candle(
                (int)$row[0],
                (float)$row[1],
                (float)$row[2],
                (float)$row[3],
                (float)$row[4],
                (float)$row[5],
            );
        }
        return $candles;
    }

    /**
     * Imports an Exness Tick CSV/ZIP (`Exness_XAUUSD_*.zip` with `"Exness","Symbol","Timestamp","Bid","Ask"`)
     * or standard MT5 OHLC CSV, aggregating ticks into $interval OHLCV candles.
     *
     * @return list<Candle>
     */
    public static function importCsvOrZip(string $filePath, string $interval): array
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("File not found: $filePath");
        }
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true || $zip->numFiles < 1) {
                throw new RuntimeException("Cannot open ZIP archive: $filePath");
            }
            $stream = $zip->getStream($zip->getNameIndex(0));
            if ($stream === false) {
                $zip->close();
                throw new RuntimeException("Cannot read CSV stream inside ZIP");
            }
            $candles = self::parseStream($stream, self::intervalMs($interval));
            fclose($stream);
            $zip->close();
            return $candles;
        }
        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open CSV: $filePath");
        }
        try {
            return self::parseStream($fp, self::intervalMs($interval));
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param resource $stream
     * @return list<Candle>
     */
    private static function parseStream($stream, int $bucketMs): array
    {
        $headerLine = fgets($stream);
        if ($headerLine === false) {
            return [];
        }
        $isExnessTick = stripos($headerLine, 'Bid') !== false && stripos($headerLine, 'Ask') !== false;
        $candles = [];
        $curBucket = -1;
        $o = $h = $l = $c = $v = 0.0;

        while (($line = fgets($stream)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, str_contains($line, "\t") ? "\t" : ',');
            if ($isExnessTick && count($cols) >= 5) {
                // "Exness","XAUUSD","2026-06-05 04:33:12.125Z",4439.650,4439.799
                $ts = strtotime((string)$cols[2]);
                if ($ts === false) {
                    continue;
                }
                $mid = ((float)$cols[3] + (float)$cols[4]) * 0.5;
                $bucket = intdiv($ts * 1000, $bucketMs) * $bucketMs;
                if ($bucket !== $curBucket) {
                    if ($curBucket >= 0) {
                        $candles[] = new Candle($curBucket, $o, $h, $l, $c, $v);
                    }
                    $curBucket = $bucket;
                    $o = $h = $l = $c = $mid;
                    $v = 1.0;
                } else {
                    if ($mid > $h) { $h = $mid; }
                    if ($mid < $l) { $l = $mid; }
                    $c = $mid;
                    $v += 1.0;
                }
            } elseif (count($cols) >= 5) {
                // Standard OHLCV CSV: time,open,high,low,close[,volume]
                $rawT = trim((string)$cols[0]);
                $tsMs = is_numeric($rawT)
                    ? ((int)$rawT > 10_000_000_000 ? (int)$rawT : (int)$rawT * 1000)
                    : ((int)strtotime($rawT) * 1000);
                if ($tsMs <= 0) {
                    continue;
                }
                $candles[] = new Candle(
                    $tsMs,
                    (float)$cols[1],
                    (float)$cols[2],
                    (float)$cols[3],
                    (float)$cols[4],
                    isset($cols[5]) ? (float)$cols[5] : 1.0,
                );
            }
            if (count($candles) >= 5000) {
                break;
            }
        }
        if ($isExnessTick && $curBucket >= 0) {
            $candles[] = new Candle($curBucket, $o, $h, $l, $c, $v);
        }
        return $candles;
    }

    /**
     * Deterministic multi-regime dataset (trend + chop + breakout) calibrated to
     * real 2026 price levels (e.g. XAUUSDz ~$4,420, BTCUSDT ~$94,000) so first launch
     * and offline headless tests work immediately without network dependency.
     *
     * @return list<Candle>
     */
    public static function syntheticDataset(string $market, string $symbol, string $interval, int $count = 600): array
    {
        [$base, $vol] = match ($symbol) {
            'XAUUSDz', 'PAXGUSDT' => [4380.0, 0.0028],
            'EURUSDz' => [1.0850, 0.0012],
            'USDJPYz' => [153.40, 0.0018],
            'ETHUSDT' => [3420.0, 0.0085],
            'SOLUSDT' => [195.0, 0.0115],
            default => [92500.0, 0.0068],
        };
        $stepMs = self::intervalMs($interval);
        $startMs = 1780617600_000 - $count * $stepMs; // anchored at 2026-06-05 UTC

        $seed = crc32("$market:$symbol:$interval") & 0x7fffffff;
        $nextRand = static function () use (&$seed): float {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return ($seed / 2147483647.0) * 2.0 - 1.0;
        };

        $candles = [];
        $price = $base;
        for ($i = 0; $i < $count; $i++) {
            // Multi-wave realistic intraday swings + macro trend legs + consolidation chop
            $macro = sin($i * 0.038) * ($vol * 0.32) + sin($i * 0.095) * ($vol * 0.22);
            $regimeBias = ($i > 120 && $i < 310) || ($i > 420 && $i < 580) ? ($vol * 0.14) : (-$vol * 0.04);
            $ret = $regimeBias + $macro + $vol * ($nextRand() * 0.92);
            $open = $price;
            $close = max($base * 0.25, $open * (1.0 + $ret));
            $wickUp = abs($nextRand()) * $vol * 0.85 * $open;
            $wickDn = abs($nextRand()) * $vol * 0.85 * $open;
            $high = max($open, $close) + $wickUp;
            $low = max($base * 0.2, min($open, $close) - $wickDn);
            $volume = 100.0 + abs($ret / max(1e-6, $vol)) * 420.0;

            $candles[] = new Candle($startMs + $i * $stepMs, $open, $high, $low, $close, $volume);
            $price = $close;
        }
        return $candles;
    }
}
