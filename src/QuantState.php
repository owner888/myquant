<?php
declare(strict_types=1);

namespace MyQuant;

use MyPHP\{App, PathName, Window};
use MyQuant\Data\{Candle, CandleStore, MarketFeed};
use MyQuant\Engine\{Backtester, SweepRunner};

final class QuantState
{
    public string $tab = 'lab';
    public string $market = 'forex';
    public string $symbol = 'XAUUSDz';
    public string $interval = '15m';

    public float $fastEma = 12.0;
    public float $slowEma = 36.0;
    public float $atrMult = 2.0;
    public float $riskPct = 1.0; // % of equity per trade
    public float $feeBps = 0.2;  // per-leg bps (0.2 bps for Exness Zero XAUUSDz, 8.0 bps for Crypto)

    public bool $syncing = false;
    public string $statusText = '';
    public string $importPath = '';
    public ?Window $window = null;

    /** @var list<Candle> */
    public array $candles = [];
    public array $report = [];
    public array $sweep = [];

    private ?CandleStore $store = null;
    private int $seq = 0;

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

    public function switchMarket(string $newMarket): void
    {
        if (!isset(MarketFeed::MARKETS[$newMarket])) {
            return;
        }
        $this->market = $newMarket;
        $symbols = MarketFeed::MARKETS[$newMarket]['symbols'];
        if (!in_array($this->symbol, $symbols, true)) {
            $this->symbol = $symbols[0];
        }
        $this->feeBps = MarketFeed::MARKETS[$newMarket]['defaultFeeBps'];
        $this->loadDataset(false);
    }

    public function selectSymbol(string $sym): void
    {
        $this->symbol = $sym;
        $this->loadDataset(false);
    }

    public function selectInterval(string $iv): void
    {
        $this->interval = $iv;
        $this->loadDataset(false);
    }

    public function loadDataset(bool $preferOnline): void
    {
        $cached = $this->store?->load($this->market, $this->symbol, $this->interval, 1000) ?? [];
        if ($cached !== [] && !$preferOnline) {
            $this->candles = $cached;
            $this->statusText = sprintf('已加载本地缓存 · %s (%s) · %d 根 K 线', $this->symbol, $this->interval, count($cached));
            $this->recomputeAll();
            return;
        }

        if (!$preferOnline) {
            $seed = MarketFeed::syntheticDataset($this->market, $this->symbol, $this->interval, 650);
            $this->store?->save($this->market, $this->symbol, $this->interval, $seed);
            $this->candles = $seed;
            $this->statusText = sprintf('内置 %s (%s) 基准行情 · %d 根 K 线（点击「同步实盘行情」拉取最新数据）', $this->symbol, $this->interval, count($seed));
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
                $fresh = MarketFeed::fetchOnline($market, $symbol, $interval, 800);
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
                $this->candles = $fresh;
                $this->statusText = sprintf('已同步线上实盘行情 · %s (%s) · %d 根 K 线', $symbol, $interval, count($fresh));
                $this->recomputeAll();
            } else {
                $this->statusText = '线上同步失败（继续使用本地行情）: ' . $err;
            }
        });
    }

    public function importLocalFile(): void
    {
        $path = trim($this->importPath);
        if ($path === '') {
            $this->statusText = '请输入 Exness Tick ZIP 或 MT5 OHLC CSV 路径';
            return;
        }
        try {
            $imported = MarketFeed::importCsvOrZip($path, $this->interval);
            if (count($imported) < 30) {
                $this->statusText = '导入失败：有效 K 线不足 30 根';
                return;
            }
            $this->store?->save($this->market, $this->symbol, $this->interval, $imported);
            $this->candles = $imported;
            $this->statusText = sprintf('成功从文件导入 %d 根 %s (%s) K 线', count($imported), $this->symbol, $this->interval);
            $this->recomputeAll();
        } catch (\Throwable $e) {
            $this->statusText = '导入异常: ' . $e->getMessage();
        }
    }

    public function recomputeAll(): void
    {
        $this->runBacktest();
        $this->runSweep();
    }

    public function runBacktest(): void
    {
        $fast = max(2, (int)round($this->fastEma));
        $slow = max($fast + 2, (int)round($this->slowEma));
        $this->slowEma = (float)$slow;

        $this->report = Backtester::run(
            candles: $this->candles,
            fastPeriod: $fast,
            slowPeriod: $slow,
            atrMult: max(0.5, $this->atrMult),
            riskPct: max(0.001, $this->riskPct / 100.0),
            feeRate: max(0.0, $this->feeBps / 10000.0),
            initialEquity: 10000.0,
            maxLeverage: $this->market === 'forex' ? 3.0 : 1.0,
        );
    }

    public function runSweep(): void
    {
        $this->sweep = SweepRunner::sweep(
            candles: $this->candles,
            fastList: [6, 9, 12, 16, 20, 25],
            slowList: [24, 32, 42, 55, 72, 96],
            atrMults: [1.5, 2.0, 2.5, 3.0],
            initialEquity: 10000.0,
            feeRate: max(0.0, $this->feeBps / 10000.0),
            riskPct: max(0.001, $this->riskPct / 100.0),
            maxLeverage: $this->market === 'forex' ? 3.0 : 1.0,
        );
    }

    /**
     * Applies a parameter combination from the C FFI grid sweep directly into the Backtest Lab.
     */
    public function applySweepRow(int $fast, int $slow, float $atrMult): void
    {
        $this->fastEma = (float)$fast;
        $this->slowEma = (float)$slow;
        $this->atrMult = $atrMult;
        $this->runBacktest();
        $this->tab = 'lab';
        $this->statusText = sprintf('已套用高原参数：Fast EMA %d / Slow EMA %d / ATR %.1fx', $fast, $slow, $atrMult);
    }
}
