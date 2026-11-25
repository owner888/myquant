#ifndef MYQUANT_SWEEP_H
#define MYQUANT_SWEEP_H

#include <stdint.h>

#ifdef _WIN32
#define MQ_EXPORT __declspec(dllexport)
#else
#define MQ_EXPORT
#endif

#ifdef __cplusplus
extern "C" {
#endif

#define MQ_SWEEP_VERSION 3
#define MQ_METRICS_STRIDE 8
#define MQ_BACKTEST_SUMMARY_LEN 14
#define MQ_TRADE_STRIDE 8
#define MQ_ATR_PERIOD 14

#define MQ_DIR_LONG 1
#define MQ_DIR_BOTH 0
#define MQ_DIR_SHORT -1

MQ_EXPORT int mq_sweep_version(void);
MQ_EXPORT int mq_cpu_threads(void);

/* ---- Columnar SoA Dataset + Binary OHLC LOD Pyramid ---- */

MQ_EXPORT int mq_dataset_alloc(
    const int64_t *open_time_ms,
    const double *opens,
    const double *highs,
    const double *lows,
    const double *closes,
    const double *volumes,
    int n_candles
);

MQ_EXPORT int mq_dataset_create_empty(int initial_cap);

MQ_EXPORT int mq_dataset_append_batch(
    int ds_id,
    const int64_t *open_time_ms,
    const double *opens,
    const double *highs,
    const double *lows,
    const double *closes,
    const double *volumes,
    int n_candles
);

MQ_EXPORT int mq_dataset_finalize(int ds_id);

MQ_EXPORT int mq_dataset_generate_mock(
    int n_candles,
    double base_price,
    double bar_volatility,
    int64_t start_time_ms,
    int64_t step_ms,
    uint64_t seed
);

MQ_EXPORT void mq_dataset_free(int ds_id);
MQ_EXPORT int mq_dataset_count(int ds_id);
MQ_EXPORT int64_t mq_dataset_memory_bytes(int ds_id);

MQ_EXPORT int mq_dataset_get_last(
    int ds_id,
    int64_t *out_open_time_ms,
    double *out_ohlcv
);

MQ_EXPORT int mq_dataset_update_last(
    int ds_id,
    int64_t open_time_ms,
    double open,
    double high,
    double low,
    double close,
    double volume
);

/* O(log N) viewport query over the binary LOD pyramid: returns <= max_out buckets */
MQ_EXPORT int mq_dataset_query_lod(
    int ds_id,
    int from_idx,
    int to_idx,
    int max_out,
    double *out_xs,
    double *out_closes,
    double *out_highs,
    double *out_lows,
    double *out_extremes
);

/* ---- Zero-Copy Backtest & Multi-Threaded Grid Sweep on Resident SoA Dataset ---- */

MQ_EXPORT int mq_backtest_on_dataset(
    int ds_id,
    int fast_period,
    int slow_period,
    double atr_mult,
    double initial_equity,
    double fee_rate,
    double risk_pct,
    double max_leverage,
    int direction,
    double *out_summary,
    double *out_xs,
    double *out_strategy_ys,
    double *out_hold_ys,
    double *out_drawdown_ys,
    int max_curve_pts,
    double *out_trades,
    int max_trades
);

MQ_EXPORT int mq_sweep_on_dataset(
    int ds_id,
    const int *fast_list,
    int n_fast,
    const int *slow_list,
    int n_slow,
    const double *atr_mults,
    int n_atr,
    double initial_equity,
    double fee_rate,
    double risk_pct,
    double max_leverage,
    int direction,
    int n_threads,
    double *out_metrics,
    int max_combos
);

/* Legacy raw-array entry point kept for backwards compatibility */
MQ_EXPORT int mq_sweep_ema_atr(
    const double *highs,
    const double *lows,
    const double *closes,
    int n_candles,
    const int *fast_list,
    int n_fast,
    const int *slow_list,
    int n_slow,
    const double *atr_mults,
    int n_atr,
    double initial_equity,
    double fee_rate,
    double risk_pct,
    double max_leverage,
    double *out_metrics,
    int max_combos
);

#ifdef __cplusplus
}
#endif

#endif
