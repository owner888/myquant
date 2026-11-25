<?php
declare(strict_types=1);

namespace MyQuant;

use MyPHP\{App, Dialog, FileFilter, OpenDialogOptions, PathName, Window};
use MyQuant\Data\{Candle, CandleStore, DatasetHandle, MarketFeed};
use MyQuant\Engine\{Backtester, SweepRunner};

final class QuantState
{
    public string $tab = 'lab';
    public string $market = 'forex';
    public string $symbol = 'XAUUSDz';
    public string $interval = '15m';
    public string $scaleMode = 'live'; // 'live' (SQLite/API), '100k', '1m'
    public string $direction = 'long'; // 'long' (1), 'both' (0), 'short' (-1)

    public float $fastEma = 12.0;
    public float $slowEma = 36.0;
    public float $atrMult = 2.0;
    public float $riskPct = 1.0;
    public float $feeBps = 0.2;

    // Viewport pan & zoom over the C LOD pyramid (0..100%)
    public float $viewStartPct = 0.0;
    public float $viewSpanPct = 100.0;

    public bool $syncing = false;
    public bool $importing = false;
    public bool $streaming = false;
    public int $streamTicks = 0;
    public float $lastPrice = 0.0;
    public string $statusText = '';
    public string $importedFileName = '';
    public ?Window $window = null;

    public int $barCount = 0;
    public float $cMemoryMb = 0.0;
    public float $phpMemoryMb = 0.0;
    public array $lodView = [];
    public array $report = [];
    public array $sweep = [];

    private ?CandleStore $store = null;

    /** 样本内 / 样本外切分：前 TRAIN_PCT% 的 K 线用来挑参数，其余只做验证；任一段短于下限就不切分。 */
    public const TRAIN_PCT = 70;
    private const MIN_TRAIN_BARS = 700;
    private const MIN_TEST_BARS = 300;
    /** 统计「样本内靠前的参数在样本外是否仍盈利」时看排行榜的前几行。 */
    private const OOS_TOP_ROWS = 20;
    private ?DatasetHandle $dataset = null;
    private int $seq = 0;
    private int $streamSeq = 0;

    public function __construct(?string $dbPath = null)
    {
        $resolvedDb = $dbPath ?? (App::path(PathName::UserData) . '/myquant.sqlite');
        $this->store = new CandleStore($resolvedDb);
        $this->loadDataset(false);
    }

    /**
     * @return list<string>
     */
    public function currentSymbols(): array
    {
        return MarketFeed::MARKETS[$this->market]['symbols'] ?? ['BTCUSDT'];
    }

    public function directionCode(): int
    {
        return match ($this->direction) {
            'short' => Backtester::DIR_SHORT,
            'both' => Backtester::DIR_BOTH,
            default => Backtester::DIR_LONG,
        };
    }

    public function directionLabel(): string
    {
        return match ($this->direction) {
            'short' => '仅做空',
            'both' => '多空双向',
            default => '仅做多',
        };
    }

    public function switchMarket(string $newMarket): void
    {
        if (!isset(MarketFeed::MARKETS[$newMarket])) {
            return;
        }
        $this->stopStream(false);
        $this->market = $newMarket;
        $symbols = MarketFeed::MARKETS[$newMarket]['symbols'];
        if (!in_array($this->symbol, $symbols, true)) {
            $this->symbol = $symbols[0];
        }
        $this->feeBps = MarketFeed::MARKETS[$newMarket]['defaultFeeBps'];
        $this->applyScaleOrLoad(false);
    }

    public function selectSymbol(string $sym): void
    {
        $this->stopStream(false);
        $this->symbol = $sym;
        $this->applyScaleOrLoad(false);
    }

    public function selectInterval(string $iv): void
    {
        $this->stopStream(false);
        $this->interval = $iv;
        $this->applyScaleOrLoad(false);
    }

    public function selectScaleMode(string $mode): void
    {
        if (!in_array($mode, ['live', '100k', '1m'], true)) {
            return;
        }
        $this->stopStream(false);
        $this->scaleMode = $mode;
        $this->applyScaleOrLoad(false);
    }

    public function selectDirection(string $dir): void
    {
        if (!in_array($dir, ['long', 'both', 'short'], true)) {
            return;
        }
        $this->direction = $dir;
        $this->recomputeAll();
        $this->statusText = sprintf(
            '策略方向已切换为「%s」 · %s (%s) · %s 根 K 线（净收益 %+.2f%%）',
            $this->directionLabel(),
            $this->symbol,
            $this->interval,
            number_format($this->barCount),
            (float)($this->report['netReturnPct'] ?? 0.0),
        );
    }

    private function applyScaleOrLoad(bool $preferOnline): void
    {
        if ($this->scaleMode === '100k') {
            $this->loadNativeMock(100_000);
        } elseif ($this->scaleMode === '1m') {
            $this->loadNativeMock(1_000_000);
        } else {
            $this->loadDataset($preferOnline);
        }
    }

    /**
     * Generates 100K or 1,000,000 bars directly in C Columnar SoA memory + Binary LOD Pyramid (zero PHP objects).
     */
    public function loadNativeMock(int $count): void
    {
        [$basePrice, $vol] = match ($this->symbol) {
            'XAUUSDz', 'PAXGUSDT' => [4380.0, 0.00075],
            'EURUSDz' => [1.0850, 0.00035],
            'USDJPYz' => [153.40, 0.00045],
            'ETHUSDT' => [3420.0, 0.0016],
            'SOLUSDT' => [195.0, 0.0022],
            default => [92500.0, 0.0013],
        };
        $stepMs = MarketFeed::intervalMs($this->interval);
        $startMs = 1780617600_000 - $count * $stepMs;
        $seed = (crc32("{$this->market}:{$this->symbol}:{$this->interval}:$count") & 0x7fffffff) + 17;

        $t0 = hrtime(true);
        $this->dataset?->release();
        $this->dataset = DatasetHandle::generateMock($count, $basePrice, $vol, $startMs, $stepMs, $seed);
        $genMs = (hrtime(true) - $t0) / 1e6;

        $this->syncTelemetry();
        $this->recomputeAll();
        $this->statusText = sprintf(
            'C 原生 SoA 驻留 · %s · %s 根 K 线（建模+LOD金字塔 %.1f ms · C 内存 %.1f MB · PHP 堆 %.1f MB）',
            $this->symbol,
            number_format($this->barCount),
            $genMs,
            $this->cMemoryMb,
            $this->phpMemoryMb,
        );
    }

    public function loadDataset(bool $preferOnline): void
    {
        $this->scaleMode = 'live';
        if (!$preferOnline) {
            $cachedDs = $this->store?->loadDataset($this->market, $this->symbol, $this->interval);
            if ($cachedDs !== null && $cachedDs->count > 0) {
                $this->dataset?->release();
                $this->dataset = $cachedDs;
                $this->syncTelemetry();
                $this->statusText = sprintf(
                    'SQLite → C SoA 流式驻留 · %s (%s) · %s 根 K 线（PHP 零对象驻留）',
                    $this->symbol,
                    $this->interval,
                    number_format($this->barCount),
                );
                $this->recomputeAll();
                return;
            }
            $cachedDs?->release();

            $seed = MarketFeed::syntheticDataset($this->market, $this->symbol, $this->interval, 650);
            $this->store?->save($this->market, $this->symbol, $this->interval, $seed);
            $this->installCandles($seed);
            unset($seed);
            $this->statusText = sprintf(
                '内置 %s (%s) 基准行情 · %d 根 K 线（支持同步实盘 / 实时流 / 100万根压测）',
                $this->symbol,
                $this->interval,
                $this->barCount,
            );
            $this->recomputeAll();
            return;
        }

        $seq = ++$this->seq;
        $market = $this->market;
        $symbol = $this->symbol;
        $interval = $this->interval;
        $this->syncing = true;
        $this->statusText = "正在拉取 {$symbol} ({$interval}) 最新行情…";

        App::go(function () use ($seq, $market, $symbol, $interval): void {
            try {
                $fresh = MarketFeed::fetchOnline($market, $symbol, $interval, 1000);
                $err = null;
            } catch (\Throwable $e) {
                $fresh = [];
                $err = $e->getMessage();
            }
            if ($seq !== $this->seq) {
                return;
            }
            $this->syncing = false;
            if ($fresh !== []) {
                $this->store?->save($market, $symbol, $interval, $fresh);
                unset($fresh);
                $mergedDs = $this->store?->loadDataset($market, $symbol, $interval);
                if ($mergedDs !== null && $mergedDs->count > 0) {
                    $this->dataset?->release();
                    $this->dataset = $mergedDs;
                    $this->syncTelemetry();
                }
                $this->statusText = sprintf('已同步线上实盘 → SQLite + C SoA · %s (%s) · %s 根 K 线', $symbol, $interval, number_format($this->barCount));
                $this->recomputeAll();
            } else {
                $this->statusText = '线上同步失败（继续使用当前数据）: ' . $err;
            }
        });
    }

    /**
     * 拉取最近 $days 天的真实历史 K 线（Binance 翻页），替换该品种/周期已存的行情（含内置合成基准），
     * 逐页落盘 SQLite 并在状态栏报告进度；完成后流式装入 C SoA 并重算。
     */
    public function syncHistory(int $days): void
    {
        if ($this->syncing || !isset(MarketFeed::HISTORY_SPANS[$days])) {
            return;
        }
        if (MarketFeed::onlineSymbol($this->symbol) === null) {
            $this->statusText = "{$this->symbol} 没有线上行情来源，请用「导入数据」导入 Exness Tick ZIP 或 MT5 CSV";
            return;
        }
        $this->stopStream(false);
        $this->scaleMode = 'live';
        $seq = ++$this->seq;
        [$market, $symbol, $interval] = [$this->market, $this->symbol, $this->interval];
        $pages = MarketFeed::historyPages($interval, $days);
        $label = MarketFeed::HISTORY_SPANS[$days];
        $this->syncing = true;
        $this->statusText = sprintf('正在拉取 %s (%s) %s真实历史 · 共约 %d 页…', $symbol, $interval, $label, $pages);

        App::go(function () use ($seq, $market, $symbol, $interval, $days, $pages, $label): void {
            $started = microtime(true);
            $fromMs = (int)(microtime(true) * 1000) - $days * 86_400_000;
            $got = 0;
            try {
                $total = MarketFeed::fetchHistory($symbol, $interval, $fromMs, function (array $page, int $pageNo) use ($seq, $market, $symbol, $interval, $pages, $label, &$got): bool {
                    if ($seq !== $this->seq) {
                        return false;
                    }
                    if ($pageNo === 1) {
                        $this->store?->clear($market, $symbol, $interval); // 第一页到手才清旧数据：失败时原行情仍在
                    }
                    $this->store?->save($market, $symbol, $interval, $page);
                    $got += count($page);
                    $this->statusText = sprintf('正在拉取 %s (%s) %s真实历史 · 第 %d / %d 页 · 已得 %s 根', $symbol, $interval, $label, $pageNo, $pages, number_format($got));
                    return true;
                });
                $err = null;
            } catch (\Throwable $e) {
                $total = 0;
                $err = $e->getMessage();
            }
            if ($seq !== $this->seq) {
                return;
            }
            $this->syncing = false;
            $ds = $this->store?->loadDataset($market, $symbol, $interval);
            if ($ds !== null && $ds->count > 0) {
                $this->dataset?->release();
                $this->dataset = $ds;
                $this->syncTelemetry();
                $this->recomputeAll();
            } else {
                $ds?->release();
            }
            $this->statusText = $err === null
                ? sprintf('已拉取真实历史 → SQLite + C SoA · %s (%s) · %s · %s 根 K 线 · 耗时 %.1f 秒', $symbol, $interval, $label, number_format($this->barCount), microtime(true) - $started)
                : sprintf('历史拉取中断（已保留拉到的 %s 根）: %s', number_format($this->barCount), $err);
        });
    }

    /**
     * Toggles the real-time O(log N) incremental stream (`mq_dataset_update_last` + SQLite upsert).
     */
    public function toggleStream(): void
    {
        if ($this->streaming) {
            $this->stopStream(true);
            return;
        }
        $this->streaming = true;
        $mySeq = ++$this->streamSeq;
        $onlineCapable = App::running() && $this->scaleMode === 'live';
        $this->stepStream($onlineCapable);

        if (App::running()) {
            App::go(function () use ($mySeq): void {
                while ($this->streaming && $mySeq === $this->streamSeq) {
                    App::sleep(2.0);
                    if (!$this->streaming || $mySeq !== $this->streamSeq) {
                        break;
                    }
                    $this->stepStream($this->scaleMode === 'live');
                }
            });
        }
    }

    public function stopStream(bool $updateStatus = true): void
    {
        if (!$this->streaming) {
            return;
        }
        $this->streaming = false;
        ++$this->streamSeq;
        if ($updateStatus) {
            $this->statusText = sprintf(
                '已停止实时增量流 · %s (%s) · 累计推送 %d Tick · 当前 %s 根 K 线',
                $this->symbol,
                $this->interval,
                $this->streamTicks,
                number_format($this->barCount),
            );
        }
    }

    /**
     * Applies a single incremental tick or explicit Candle to C SoA (`mq_dataset_update_last`) + SQLite.
     */
    public function stepStream(bool $preferOnline = false, ?Candle $explicit = null): void
    {
        if ($this->dataset === null) {
            return;
        }
        ++$this->streamTicks;
        if ($explicit !== null) {
            $updates = [$explicit];
            $source = '外部推送流';
        } else {
            $last = $this->dataset->lastCandle();
            $res = MarketFeed::nextStreamTick(
                $this->market,
                $this->symbol,
                $this->interval,
                $last,
                $preferOnline,
                $this->streamTicks,
            );
            $updates = $res['candles'];
            $source = $res['source'];
        }

        foreach ($updates as $c) {
            $this->dataset->updateLast($c);
            $this->lastPrice = $c->close;
        }
        if ($this->scaleMode === 'live' && $updates !== []) {
            $this->store?->save($this->market, $this->symbol, $this->interval, $updates);
        }

        $this->syncTelemetry();
        $this->runBacktest();
        $priceFmt = $this->lastPrice >= 100.0 ? sprintf('%.2f', $this->lastPrice) : sprintf('%.4f', $this->lastPrice);
        $this->statusText = sprintf(
            '实时流运行中 (%s #%d) · %s 最新价 %s · %s 根 K 线 · O(log N) LOD %.1f µs',
            $source,
            $this->streamTicks,
            $this->symbol,
            $priceFmt,
            number_format($this->barCount),
            (float)($this->lodView['elapsedUs'] ?? 0.0),
        );
    }

    /**
     * Uploads PHP candles into C SoA once and immediately drops the PHP array.
     *
     * @param list<Candle> $candles
     */
    private function installCandles(array $candles): void
    {
        $this->dataset?->release();
        $this->dataset = DatasetHandle::fromCandles($candles);
        $this->syncTelemetry();
    }

    private function syncTelemetry(): void
    {
        $this->barCount = $this->dataset?->count ?? 0;
        $this->cMemoryMb = $this->dataset?->cMemoryMb ?? 0.0;
        $this->phpMemoryMb = memory_get_usage() / 1048576.0;
        $last = $this->dataset?->lastCandle();
        if ($last !== null) {
            $this->lastPrice = $last->close;
        }
        $this->updateViewport();
    }

    public function updateViewport(): void
    {
        if ($this->dataset === null || $this->barCount <= 0) {
            $this->lodView = ['xs' => [0.0, 1.0], 'closes' => [1.0, 1.0], 'low' => 0.0, 'high' => 1.0, 'elapsedUs' => 0.0, 'buckets' => 0];
            return;
        }
        $spanPct = max(1.0, min(100.0, $this->viewSpanPct));
        $startPct = max(0.0, min(100.0 - $spanPct, $this->viewStartPct));
        $this->viewStartPct = $startPct;
        $this->viewSpanPct = $spanPct;

        $from = (int)floor($this->barCount * ($startPct / 100.0));
        $to = max($from + 8, (int)ceil($this->barCount * (($startPct + $spanPct) / 100.0)));
        $this->lodView = $this->dataset->queryLod($from, min($this->barCount, $to), 420);
    }

    /**
     * Opens the native OS file picker sheet/dialog to choose a local Exness Tick ZIP or MT5 OHLC CSV.
     */
    public function pickAndImportFile(): void
    {
        if ($this->importing) {
            return;
        }
        App::go(function (): void {
            $paths = Dialog::open(new OpenDialogOptions(
                parent: $this->window,
                title: '选择 Exness Tick ZIP 或 MT5 OHLC CSV',
                buttonLabel: '导入行情',
                filters: [
                    new FileFilter('历史行情数据 (*.zip, *.csv, *.tsv, *.txt)', ['zip', 'csv', 'tsv', 'txt']),
                ],
            ));
            if ($paths === [] || $paths[0] === '') {
                return;
            }
            $this->importLocalFile($paths[0]);
        });
    }

    public function importLocalFile(string $filePath): void
    {
        $path = trim($filePath);
        if ($path === '') {
            return;
        }
        $this->stopStream(false);
        $this->importing = true;
        try {
            $t0 = hrtime(true);
            $newDs = MarketFeed::importIntoStoreAndDataset(
                $path,
                $this->market,
                $this->symbol,
                $this->interval,
                $this->store,
            );
            if ($newDs->count < 30) {
                $newDs->release();
                $this->statusText = '导入失败：有效 K 线不足 30 根';
                return;
            }
            $this->scaleMode = 'live';
            $this->importedFileName = basename($path);
            $this->dataset?->release();
            $this->dataset = $newDs;
            $importMs = (hrtime(true) - $t0) / 1e6;
            $this->syncTelemetry();
            $this->recomputeAll();
            $this->statusText = sprintf(
                '已导入 %s → SQLite + C SoA · %s 根 %s (%s) K 线（耗时 %.1f ms · C %.1f MB / PHP %.1f MB）',
                $this->importedFileName,
                number_format($this->barCount),
                $this->symbol,
                $this->interval,
                $importMs,
                $this->cMemoryMb,
                $this->phpMemoryMb,
            );
        } catch (\Throwable $e) {
            $this->statusText = '导入异常: ' . $e->getMessage();
        } finally {
            $this->importing = false;
        }
    }

    public function recomputeAll(): void
    {
        $this->updateViewport();
        $this->runBacktest();
        $this->runSweep();
    }

    public function runBacktest(): void
    {
        if ($this->dataset === null) {
            return;
        }
        $fast = max(2, (int)round($this->fastEma));
        $slow = max($fast + 2, (int)round($this->slowEma));
        $this->slowEma = (float)$slow;

        $this->report = Backtester::runOnDataset(
            ds: $this->dataset,
            fastPeriod: $fast,
            slowPeriod: $slow,
            atrMult: max(0.5, $this->atrMult),
            riskPct: max(0.001, $this->riskPct / 100.0),
            feeRate: max(0.0, $this->feeBps / 10000.0),
            initialEquity: 10000.0,
            maxLeverage: $this->market === 'forex' ? 3.0 : 1.0,
            direction: $this->directionCode(),
        );
        $this->phpMemoryMb = memory_get_usage() / 1048576.0;
    }

    public function runSweep(): void
    {
        if ($this->dataset === null) {
            return;
        }
        $sweepOn = fn(DatasetHandle $ds): array => SweepRunner::sweepOnDataset(
            ds: $ds,
            fastList: [6, 9, 12, 16, 20, 25],
            slowList: [24, 32, 42, 55, 72, 96],
            atrMults: [1.5, 2.0, 2.5, 3.0],
            initialEquity: 10000.0,
            feeRate: max(0.0, $this->feeBps / 10000.0),
            riskPct: max(0.001, $this->riskPct / 100.0),
            maxLeverage: $this->market === 'forex' ? 3.0 : 1.0,
            direction: $this->directionCode(),
        );
        [$train, $test] = $this->splitForValidation();
        if ($train === null || $test === null) {
            $train?->release();
            $test?->release();
            $this->sweep = $sweepOn($this->dataset);
            unset($this->sweep['byId']);
            $this->sweep['oos'] = null; // 行情不在 SQLite（压测规模）或太短：全段扫描，无样本外验证
        } else {
            try {
                $this->sweep = self::withOutOfSample($sweepOn($train), $sweepOn($test), $train->count, $test->count);
            } finally {
                $train->release();
                $test->release();
            }
        }
        $this->phpMemoryMb = memory_get_usage() / 1048576.0;
    }

    /**
     * 把已落盘的行情按时间切成前 TRAIN_PCT% 的样本内段和其余的样本外段，各自装入独立的 C SoA 数据集。
     * 只对 SQLite 里与当前数据集一致的行情（标准行情：内置 / 同步 / 历史 / 导入）切分，且两段都要够长；否则返回 [null, null]。
     *
     * @return array{0: ?DatasetHandle, 1: ?DatasetHandle}
     */
    private function splitForValidation(): array
    {
        if ($this->scaleMode !== 'live' || $this->store === null) {
            return [null, null];
        }
        $total = $this->store->count($this->market, $this->symbol, $this->interval);
        $nTrain = (int)floor($total * self::TRAIN_PCT / 100);
        if ($total !== $this->barCount || $nTrain < self::MIN_TRAIN_BARS || $total - $nTrain < self::MIN_TEST_BARS) {
            return [null, null];
        }
        return [
            $this->store->loadSlice($this->market, $this->symbol, $this->interval, 0, $nTrain),
            $this->store->loadSlice($this->market, $this->symbol, $this->interval, $nTrain, $total - $nTrain),
        ];
    }

    /**
     * 排行榜取样本内扫描的结果（参数只在前段上挑），每行附上同一组参数在样本外段的表现；
     * 汇总里统计样本内排名靠前且盈利的参数中有多少在样本外仍然盈利。
     *
     * @param array<string, mixed> $inSample
     * @param array<string, mixed> $outOfSample
     * @return array<string, mixed>
     */
    private static function withOutOfSample(array $inSample, array $outOfSample, int $trainBars, int $testBars): array
    {
        $byId = $outOfSample['byId'];
        $top = 0;
        $held = 0;
        foreach ($inSample['rows'] as $i => &$row) {
            $o = $byId[$row['id']] ?? null;
            $row['oos'] = $o === null ? null : [
                'netReturnPct' => $o['netReturnPct'],
                'profitFactor' => $o['profitFactor'],
                'maxDrawdownPct' => $o['maxDrawdownPct'],
                'trades' => $o['trades'],
            ];
            if ($i < self::OOS_TOP_ROWS && $row['netReturnPct'] > 0 && $o !== null) {
                $top++;
                $held += $o['netReturnPct'] > 0 ? 1 : 0;
            }
        }
        unset($row);
        $inSample['elapsedMs'] = (float)($inSample['elapsedMs'] ?? 0.0) + (float)($outOfSample['elapsedMs'] ?? 0.0);
        unset($inSample['byId']);
        $inSample['oos'] = ['trainBars' => $trainBars, 'testBars' => $testBars, 'trainPct' => self::TRAIN_PCT, 'top' => $top, 'held' => $held];
        return $inSample;
    }

    public function applySweepRow(int $fast, int $slow, float $atrMult): void
    {
        $this->fastEma = (float)$fast;
        $this->slowEma = (float)$slow;
        $this->atrMult = $atrMult;
        $this->runBacktest();
        $this->tab = 'lab';
        $this->statusText = sprintf(
            '已套用高原参数：EMA(%d, %d) · ATR %.1fx · %s（%s 根 K 线回测耗时 %.2f ms）',
            $fast,
            $slow,
            $atrMult,
            $this->directionLabel(),
            number_format($this->barCount),
            (float)($this->report['elapsedMs'] ?? 0.0),
        );
    }
}
