#ifndef MYQUANT_SWEEP_H
#define MYQUANT_SWEEP_H

#ifdef __cplusplus
extern "C" {
#endif

#define MQ_SWEEP_VERSION 1
#define MQ_METRICS_STRIDE 8
#define MQ_ATR_PERIOD 14

int mq_sweep_version(void);

int mq_sweep_ema_atr(
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
