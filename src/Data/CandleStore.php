<?php
declare(strict_types=1);

namespace MyQuant\Data;

use MyPHP\Plugins\Sqlite\{Sqlite, Statement, DB};

final class CandleStore
{
    private const MULTI_INSERT_ROWS = 300;
    private const PAGE_SIZE = 4096;

    private ?DB $db = null;

    public function __construct(private readonly string $dbPath) {}

    private function conn(): DB
    {
        if ($this->db !== null) {
            return $this->db;
        }
        $parent = dirname($this->dbPath);
        if ($this->dbPath !== ':memory:' && !is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        $db = Sqlite::open($this->dbPath);
        $db->execute(
            'CREATE TABLE IF NOT EXISTS candles (
                market TEXT NOT NULL,
                symbol TEXT NOT NULL,
                interval TEXT NOT NULL,
                open_time_ms INTEGER NOT NULL,
                open REAL NOT NULL,
                high REAL NOT NULL,
                low REAL NOT NULL,
                close REAL NOT NULL,
                volume REAL NOT NULL,
                PRIMARY KEY (market, symbol, interval, open_time_ms)
            )'
        );
        return $this->db = $db;
    }

    public function count(string $market, string $symbol, string $interval): int
    {
        $rows = $this->conn()->query(
            'SELECT COUNT(*) FROM candles WHERE market = ? AND symbol = ? AND interval = ?',
            $market,
            $symbol,
            $interval,
        );
        return isset($rows->values[0][0]) ? (int)$rows->values[0][0] : 0;
    }

    /**
     * Streams persisted SQLite candles in 4096-row pages directly into a C Columnar SoA DatasetHandle
     * (zero full PHP Candle[] array retained on heap).
     */
    public function loadDataset(string $market, string $symbol, string $interval, int $limit = 0): ?DatasetHandle
    {
        $total = $this->count($market, $symbol, $interval);
        if ($total <= 0) {
            return null;
        }

        $targetCount = $limit > 0 ? min($total, $limit) : $total;
        return $this->loadRows($market, $symbol, $interval, $total - $targetCount, $targetCount);
    }

    /**
     * 按时间升序跳过 $skip 根后取 $count 根装入一个独立的 C SoA 数据集，供样本内 / 样本外切分
     * （前段选参数、后段只验证）；越界部分截掉，取不到返回 null。调用方负责 release()。
     */
    public function loadSlice(string $market, string $symbol, string $interval, int $skip, int $count): ?DatasetHandle
    {
        $skip = max(0, $skip);
        $count = min($count, $this->count($market, $symbol, $interval) - $skip);
        return $count > 0 ? $this->loadRows($market, $symbol, $interval, $skip, $count) : null;
    }

    private function loadRows(string $market, string $symbol, string $interval, int $skip, int $targetCount): ?DatasetHandle
    {
        $minOpenTimeMs = -1;
        if ($skip > 0) {
            $bound = $this->conn()->query(
                'SELECT open_time_ms FROM candles
                 WHERE market = ? AND symbol = ? AND interval = ?
                 ORDER BY open_time_ms ASC
                 LIMIT 1 OFFSET ?',
                $market,
                $symbol,
                $interval,
                $skip - 1,
            );
            if (isset($bound->values[0][0])) {
                $minOpenTimeMs = (int)$bound->values[0][0];
            }
        }

        $ds = DatasetHandle::createEmpty($targetCount + 256);
        $cursor = $minOpenTimeMs;
        $loaded = 0;

        while ($loaded < $targetCount) {
            $fetch = min(self::PAGE_SIZE, $targetCount - $loaded);
            $rows = $this->conn()->query(
                'SELECT open_time_ms, open, high, low, close, volume
                 FROM candles
                 WHERE market = ? AND symbol = ? AND interval = ? AND open_time_ms > ?
                 ORDER BY open_time_ms ASC
                 LIMIT ?',
                $market,
                $symbol,
                $interval,
                $cursor,
                $fetch,
            );
            if ($rows->values === []) {
                break;
            }

            $times = [];
            $opens = [];
            $highs = [];
            $lows = [];
            $closes = [];
            $vols = [];
            foreach ($rows->values as $r) {
                $t = (int)$r[0];
                $times[] = $t;
                $opens[] = (float)$r[1];
                $highs[] = (float)$r[2];
                $lows[] = (float)$r[3];
                $closes[] = (float)$r[4];
                $vols[] = (float)$r[5];
                $cursor = $t;
            }
            $ds->appendBatch($times, $opens, $highs, $lows, $closes, $vols);
            $loaded += count($times);
        }

        if ($loaded <= 0) {
            $ds->release();
            return null;
        }
        return $ds->finalize();
    }

    /**
     * @return list<Candle>
     */
    public function load(string $market, string $symbol, string $interval, int $limit = 1500): array
    {
        $rows = $this->conn()->query(
            'SELECT open_time_ms, open, high, low, close, volume
             FROM candles
             WHERE market = ? AND symbol = ? AND interval = ?
             ORDER BY open_time_ms DESC
             LIMIT ?',
            $market, $symbol, $interval, $limit
        );
        $out = [];
        foreach (array_reverse($rows->values) as $r) {
            $out[] = new Candle(
                (int)$r[0],
                (float)$r[1],
                (float)$r[2],
                (float)$r[3],
                (float)$r[4],
                (float)$r[5],
            );
        }
        return $out;
    }

    /**
     * High-throughput multi-row transactional batch insert from columnar arrays.
     *
     * @param list<int> $times
     * @param list<float> $opens
     * @param list<float> $highs
     * @param list<float> $lows
     * @param list<float> $closes
     * @param list<float> $vols
     */
    public function saveRawBatch(
        string $market,
        string $symbol,
        string $interval,
        array $times,
        array $opens,
        array $highs,
        array $lows,
        array $closes,
        array $vols,
    ): void {
        $n = count($closes);
        if ($n === 0) {
            return;
        }
        $stmts = [];
        for ($offset = 0; $offset < $n; $offset += self::MULTI_INSERT_ROWS) {
            $end = min($n, $offset + self::MULTI_INSERT_ROWS);
            $count = $end - $offset;
            $placeholders = implode(',', array_fill(0, $count, '(?, ?, ?, ?, ?, ?, ?, ?, ?)'));
            $args = [];
            for ($i = $offset; $i < $end; $i++) {
                $args[] = $market;
                $args[] = $symbol;
                $args[] = $interval;
                $args[] = $times[$i];
                $args[] = $opens[$i];
                $args[] = $highs[$i];
                $args[] = $lows[$i];
                $args[] = $closes[$i];
                $args[] = $vols[$i];
            }
            $stmts[] = new Statement(
                'INSERT OR REPLACE INTO candles (market, symbol, interval, open_time_ms, open, high, low, close, volume) VALUES ' . $placeholders,
                $args,
            );
        }
        $this->conn()->transaction($stmts);
    }

    /** 删除该品种/周期已存的全部 K 线（拉取真实历史前清掉内置合成行情，避免两者混在一条序列里）。 */
    public function clear(string $market, string $symbol, string $interval): void
    {
        $this->conn()->execute('DELETE FROM candles WHERE market = ? AND symbol = ? AND interval = ?', $market, $symbol, $interval);
    }

    /**
     * 把暂存键 $fromInterval 下的全部 K 线换成 $toInterval 的行情：先删后者原有的，再改键，同一事务内完成
     * （历史拉取全部成功后才替换正式数据）。
     */
    public function promote(string $market, string $symbol, string $fromInterval, string $toInterval): void
    {
        $this->conn()->transaction([
            new Statement('DELETE FROM candles WHERE market = ? AND symbol = ? AND interval = ?', [$market, $symbol, $toInterval]),
            new Statement('UPDATE candles SET interval = ? WHERE market = ? AND symbol = ? AND interval = ?', [$toInterval, $market, $symbol, $fromInterval]),
        ]);
    }

    /**
     * @param list<Candle> $candles
     */
    public function save(string $market, string $symbol, string $interval, array $candles): void
    {
        if ($candles === []) {
            return;
        }
        $times = [];
        $opens = [];
        $highs = [];
        $lows = [];
        $closes = [];
        $vols = [];
        foreach ($candles as $c) {
            $times[] = $c->openTimeMs;
            $opens[] = $c->open;
            $highs[] = $c->high;
            $lows[] = $c->low;
            $closes[] = $c->close;
            $vols[] = $c->volume;
        }
        $this->saveRawBatch($market, $symbol, $interval, $times, $opens, $highs, $lows, $closes, $vols);
    }

    public function close(): void
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }
}
