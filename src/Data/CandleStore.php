<?php
declare(strict_types=1);

namespace MyQuant\Data;

use MyPHP\Plugins\Sqlite\{Sqlite, Statement, DB};

final class CandleStore
{
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
     * @param list<Candle> $candles
     */
    public function save(string $market, string $symbol, string $interval, array $candles): void
    {
        if ($candles === []) {
            return;
        }
        $stmts = [];
        foreach ($candles as $c) {
            $stmts[] = new Statement(
                'INSERT OR REPLACE INTO candles (market, symbol, interval, open_time_ms, open, high, low, close, volume)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$market, $symbol, $interval, $c->openTimeMs, $c->open, $c->high, $c->low, $c->close, $c->volume]
            );
        }
        $this->conn()->transaction($stmts);
    }

    public function close(): void
    {
        if ($this->db !== null) {
            $this->db->close();
            $this->db = null;
        }
    }
}
