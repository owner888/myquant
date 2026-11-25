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
    private const STREAM_FLUSH_CHUNK = 4096;

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

    /** Binance 单次 K 线请求的上限。 */
    public const PAGE_LIMIT = 1000;

    /** 可选的历史回溯天数（工具栏「拉取历史」菜单）。 */
    public const HISTORY_SPANS = [30 => '近 30 天', 90 => '近 90 天', 365 => '近 1 年', 1095 => '近 3 年'];

    /** 线上行情所用的 Binance 现货交易对；没有线上来源的品种（USDJPYz）返回 null。 */
    public static function onlineSymbol(string $symbol): ?string
    {
        return match ($symbol) {
            'XAUUSDz' => 'PAXGUSDT',
            'EURUSDz' => 'EURUSDT',
            'USDJPYz' => null,
            default => $symbol,
        };
    }

    /** 回溯 $days 天需要的请求页数（每页 PAGE_LIMIT 根）。 */
    public static function historyPages(string $interval, int $days): int
    {
        return (int)ceil($days * 86_400_000 / self::intervalMs($interval) / self::PAGE_LIMIT);
    }

    /**
     * 从 $fromMs 起逐页拉取真实历史 K 线直到当前（Binance `startTime` 翻页，每页 PAGE_LIMIT 根），
     * 每页交给 $onPage(list<Candle> $page, int $pageNo)；$onPage 返回 false 即中止（用户切换了品种）。
     * $fetchPage(string $binanceSymbol, string $interval, int $startMs, int $limit): list<Candle> 供测试替换网络请求。
     *
     * @return int 拉到的 K 线总数
     */
    public static function fetchHistory(string $symbol, string $interval, int $fromMs, callable $onPage, ?callable $fetchPage = null): int
    {
        $binanceSymbol = self::onlineSymbol($symbol) ?? throw new RuntimeException("$symbol 没有线上行情来源，请导入本地数据");
        $fetchPage ??= self::fetchKlines(...);
        $stepMs = self::intervalMs($interval);
        $total = 0;
        for ($pageNo = 1, $start = $fromMs; ; $pageNo++) {
            $page = $fetchPage($binanceSymbol, $interval, $start, self::PAGE_LIMIT);
            if ($page === []) {
                break;
            }
            $total += count($page);
            if ($onPage($page, $pageNo) === false || count($page) < self::PAGE_LIMIT) {
                break;
            }
            $start = $page[count($page) - 1]->openTimeMs + $stepMs;
        }
        return $total;
    }

    /**
     * Fetches up to 1000 recent candles online (or limit=2 for lightweight live stream updates).
     *
     * @return list<Candle>
     */
    public static function fetchOnline(string $market, string $symbol, string $interval, int $limit = 800): array
    {
        $binanceSymbol = self::onlineSymbol($symbol);
        if ($binanceSymbol === null) {
            return self::syntheticDataset($market, $symbol, $interval, max(2, $limit));
        }
        return self::fetchKlines($binanceSymbol, $interval, null, $limit);
    }

    /**
     * 一次 Binance K 线请求：最近 $limit 根，或给定 $startMs 时从该时刻起的 $limit 根。
     *
     * @return list<Candle>
     */
    private static function fetchKlines(string $binanceSymbol, string $interval, ?int $startMs, int $limit): array
    {
        $url = sprintf(
            'https://api.binance.com/api/v3/klines?symbol=%s&interval=%s&limit=%d',
            rawurlencode($binanceSymbol),
            rawurlencode($interval),
            min(self::PAGE_LIMIT, max(2, $limit))
        ) . ($startMs !== null ? '&startTime=' . $startMs : '');
        [$body, $resp] = URLSession::shared()->data(for: new URLRequest($url));
        if ($resp->statusCode < 200 || $resp->statusCode >= 300) {
            throw new RuntimeException("HTTP {$resp->statusCode} fetching $binanceSymbol ($interval)");
        }
        $raw = json_decode($body, true);
        if (!is_array($raw) || ($raw === [] && $startMs === null)) { // 翻页时空页即到头
            throw new RuntimeException("Empty candle payload for $binanceSymbol");
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
     * Produces the next incremental candle update(s) for live streaming:
     *  - Online mode: pulls the latest 2 klines from Binance REST (closed previous bar + live forming bar).
     *  - Offline / Synthetic / 100K / 1M mode: evolves a deterministic micro-tick on the current bar and
     *    rolls over into a new bar every 5 ticks so O(log N) LOD spine updates & live P&L work offline too.
     *
     * @return array{candles: list<Candle>, source: string}
     */
    public static function nextStreamTick(
        string $market,
        string $symbol,
        string $interval,
        ?Candle $last,
        bool $preferOnline,
        int $tickSeq,
    ): array {
        if ($preferOnline && $symbol !== 'USDJPYz') {
            try {
                $latest = self::fetchOnline($market, $symbol, $interval, 2);
                if ($latest !== []) {
                    return ['candles' => $latest, 'source' => 'Binance 实盘流'];
                }
            } catch (\Throwable) {
                // Fall through cleanly to local micro-tick evolution when offline
            }
        }

        [$base, $vol] = self::symbolProfile($symbol);
        $stepMs = self::intervalMs($interval);
        if ($last === null) {
            $nowBucket = intdiv((int)(microtime(true) * 1000), $stepMs) * $stepMs;
            $c = new Candle($nowBucket, $base, $base * 1.0005, $base * 0.9995, $base, 12.0);
            return ['candles' => [$c], 'source' => '增量模拟流'];
        }

        $seed = (crc32("$market:$symbol:$interval:$tickSeq") & 0x7fffffff) + 101;
        $u1 = ((($seed * 1103515245 + 12345) & 0x7fffffff) / 2147483647.0) * 2.0 - 1.0;
        $drift = sin($tickSeq * 0.37) * ($vol * 0.12);
        $deltaPct = $drift + $u1 * ($vol * 0.32);

        $rollNewBar = ($tickSeq % 5) === 0;
        if ($rollNewBar) {
            $open = $last->close;
            $close = max($base * 0.15, $open * (1.0 + $deltaPct));
            $wick = abs($u1) * $vol * 0.25 * $open;
            $high = max($open, $close) + $wick;
            $low = max($base * 0.10, min($open, $close) - $wick * 0.8);
            $next = new Candle(
                $last->openTimeMs + $stepMs,
                $open,
                $high,
                $low,
                $close,
                max(5.0, 35.0 + abs($u1) * 120.0),
            );
        } else {
            $close = max($base * 0.15, $last->close * (1.0 + $deltaPct));
            $high = max($last->high, $close);
            $low = min($last->low, $close);
            $next = new Candle(
                $last->openTimeMs,
                $last->open,
                $high,
                $low,
                $close,
                $last->volume + 8.0 + abs($u1) * 30.0,
            );
        }

        return ['candles' => [$next], 'source' => '增量行情流'];
    }

    /**
     * Streams an arbitrarily large Exness Tick ZIP/CSV or MT5 OHLC CSV in 4096-bar chunks
     * directly into SQLite (`CandleStore`) AND resident C Columnar SoA memory (`DatasetHandle`),
     * with no 5,000-bar cap and near-zero PHP heap overhead.
     */
    public static function importIntoStoreAndDataset(
        string $filePath,
        string $market,
        string $symbol,
        string $interval,
        ?CandleStore $store = null,
    ): DatasetHandle {
        $ds = DatasetHandle::createEmpty(8192);
        try {
            self::withCsvOrZipStream(
                $filePath,
                function ($stream) use ($market, $symbol, $interval, $store, $ds): void {
                    self::streamIntoSinks(
                        $stream,
                        self::intervalMs($interval),
                        0,
                        static function (array $t, array $o, array $h, array $l, array $c, array $v) use ($market, $symbol, $interval, $store, $ds): void {
                            $store?->saveRawBatch($market, $symbol, $interval, $t, $o, $h, $l, $c, $v);
                            $ds->appendBatch($t, $o, $h, $l, $c, $v);
                        },
                    );
                },
            );
            return $ds->finalize();
        } catch (\Throwable $e) {
            $ds->release();
            throw $e;
        }
    }

    /**
     * Imports an Exness Tick CSV/ZIP (`Exness_XAUUSD_*.zip` with `"Exness","Symbol","Timestamp","Bid","Ask"`)
     * or standard MT5 OHLC CSV, aggregating ticks into $interval OHLCV candles ($maxCandles = 0 => unlimited).
     *
     * @return list<Candle>
     */
    public static function importCsvOrZip(string $filePath, string $interval, int $maxCandles = 0): array
    {
        $candles = [];
        self::withCsvOrZipStream(
            $filePath,
            function ($stream) use ($interval, $maxCandles, &$candles): void {
                self::streamIntoSinks(
                    $stream,
                    self::intervalMs($interval),
                    $maxCandles,
                    static function (array $t, array $o, array $h, array $l, array $c, array $v) use (&$candles): void {
                        $n = count($c);
                        for ($i = 0; $i < $n; $i++) {
                            $candles[] = new Candle($t[$i], $o[$i], $h[$i], $l[$i], $c[$i], $v[$i]);
                        }
                    },
                );
            },
        );
        return $candles;
    }

    /**
     * @param callable(resource): void $consumer
     */
    private static function withCsvOrZipStream(string $filePath, callable $consumer): void
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
            try {
                $consumer($stream);
            } finally {
                fclose($stream);
                $zip->close();
            }
            return;
        }

        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw new RuntimeException("Cannot open CSV: $filePath");
        }
        try {
            $consumer($fp);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param resource $stream
     * @param callable(list<int>, list<float>, list<float>, list<float>, list<float>, list<float>): void $onChunk
     */
    private static function streamIntoSinks($stream, int $bucketMs, int $maxCandles, callable $onChunk): void
    {
        $firstLine = fgets($stream);
        if ($firstLine === false) {
            return;
        }
        $isExnessTick = stripos($firstLine, 'Bid') !== false && stripos($firstLine, 'Ask') !== false;

        $times = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $vols = [];
        $emitted = 0;

        $pushBar = static function (int $t, float $o, float $h, float $l, float $c, float $v) use (
            &$times, &$opens, &$highs, &$lows, &$closes, &$vols, &$emitted, $onChunk
        ): void {
            $times[] = $t;
            $opens[] = $o;
            $highs[] = $h;
            $lows[] = $l;
            $closes[] = $c;
            $vols[] = $v;
            $emitted++;
            if (count($closes) >= self::STREAM_FLUSH_CHUNK) {
                $onChunk($times, $opens, $highs, $lows, $closes, $vols);
                $times = $opens = $highs = $lows = $closes = $vols = [];
            }
        };

        $curBucket = -1;
        $o = $h = $l = $c = $v = 0.0;

        // If the first line is data (no textual header), parse it too
        $pendingLine = (!$isExnessTick && preg_match('/^\s*\d/', $firstLine) === 1) ? $firstLine : null;

        while (true) {
            if ($pendingLine !== null) {
                $line = $pendingLine;
                $pendingLine = null;
            } else {
                $line = fgets($stream);
                if ($line === false) {
                    break;
                }
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line, str_contains($line, "\t") ? "\t" : ',', '"', '');
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
                        $pushBar($curBucket, $o, $h, $l, $c, $v);
                        if ($maxCandles > 0 && $emitted >= $maxCandles) {
                            $curBucket = -1;
                            break;
                        }
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
                $pushBar(
                    $tsMs,
                    (float)$cols[1],
                    (float)$cols[2],
                    (float)$cols[3],
                    (float)$cols[4],
                    isset($cols[5]) ? (float)$cols[5] : 1.0,
                );
                if ($maxCandles > 0 && $emitted >= $maxCandles) {
                    break;
                }
            }
        }

        if ($isExnessTick && $curBucket >= 0 && ($maxCandles <= 0 || $emitted < $maxCandles)) {
            $pushBar($curBucket, $o, $h, $l, $c, $v);
        }
        if ($closes !== []) {
            $onChunk($times, $opens, $highs, $lows, $closes, $vols);
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private static function symbolProfile(string $symbol): array
    {
        return match ($symbol) {
            'XAUUSDz', 'PAXGUSDT' => [4380.0, 0.0028],
            'EURUSDz' => [1.0850, 0.0012],
            'USDJPYz' => [153.40, 0.0018],
            'ETHUSDT' => [3420.0, 0.0085],
            'SOLUSDT' => [195.0, 0.0115],
            default => [92500.0, 0.0068],
        };
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
        [$base, $vol] = self::symbolProfile($symbol);
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
