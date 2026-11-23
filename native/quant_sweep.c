#include "quant_sweep.h"
#include <math.h>
#include <stdlib.h>

#ifdef _WIN32
#define MQ_EXPORT __declspec(dllexport)
#else
#define MQ_EXPORT __attribute__((visibility("default")))
#endif

MQ_EXPORT int mq_sweep_version(void) {
    return MQ_SWEEP_VERSION;
}

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
) {
    if (!highs || !lows || !closes || !fast_list || !slow_list || !atr_mults || !out_metrics) {
        return -1;
    }
    if (n_candles < 30 || n_fast <= 0 || n_slow <= 0 || n_atr <= 0 || initial_equity <= 0.0) {
        return 0;
    }

    double *atr = (double *)malloc((size_t)n_candles * sizeof(double));
    if (!atr) {
        return -2;
    }

    /* Precompute Wilder ATR(14) once across all parameter combinations */
    double tr_sum = 0.0;
    atr[0] = highs[0] - lows[0];
    if (atr[0] <= 0.0) atr[0] = closes[0] * 0.001;
    tr_sum += atr[0];
    for (int i = 1; i < n_candles; i++) {
        double hl = highs[i] - lows[i];
        double hc = fabs(highs[i] - closes[i - 1]);
        double lc = fabs(lows[i] - closes[i - 1]);
        double tr = hl > hc ? (hl > lc ? hl : lc) : (hc > lc ? hc : lc);
        if (i < MQ_ATR_PERIOD) {
            tr_sum += tr;
            atr[i] = tr_sum / (double)(i + 1);
        } else {
            atr[i] = (atr[i - 1] * (MQ_ATR_PERIOD - 1) + tr) / (double)MQ_ATR_PERIOD;
        }
    }

    int written = 0;
    for (int fi = 0; fi < n_fast; fi++) {
        int fast_p = fast_list[fi];
        if (fast_p < 2) continue;
        double k_fast = 2.0 / ((double)fast_p + 1.0);

        for (int si = 0; si < n_slow; si++) {
            int slow_p = slow_list[si];
            if (slow_p <= fast_p) continue;
            double k_slow = 2.0 / ((double)slow_p + 1.0);

            for (int ai = 0; ai < n_atr; ai++) {
                if (written >= max_combos) {
                    free(atr);
                    return written;
                }
                double atr_m = atr_mults[ai];
                if (atr_m <= 0.1) continue;

                double cash = initial_equity;
                double qty = 0.0;
                double entry_cost = 0.0;
                double stop_price = 0.0;
                double peak_eq = initial_equity;
                double max_dd = 0.0;
                double gross_win = 0.0;
                double gross_loss = 0.0;
                int wins = 0;
                int closed = 0;

                double ema_f = closes[0];
                double ema_s = closes[0];
                int prev_bull = 0;

                for (int i = 1; i < n_candles; i++) {
                    double price = closes[i];
                    ema_f = (price - ema_f) * k_fast + ema_f;
                    ema_s = (price - ema_s) * k_slow + ema_s;
                    int bull = ema_f > ema_s ? 1 : 0;

                    if (qty > 0.0) {
                        /* Trail hard stop upward as price advances */
                        double candidate_stop = price - atr_m * atr[i];
                        if (candidate_stop > stop_price) {
                            stop_price = candidate_stop;
                        }
                        int hit_stop = lows[i] <= stop_price ? 1 : 0;
                        int flip_bear = (prev_bull == 1 && bull == 0) ? 1 : 0;

                        if (hit_stop || flip_bear) {
                            double raw_exit = hit_stop ? (stop_price < highs[i] ? stop_price : price) : price;
                            double exec_exit = raw_exit * (1.0 - fee_rate);
                            double proceeds = qty * exec_exit;
                            double pnl = proceeds - entry_cost;
                            cash += proceeds;
                            qty = 0.0;
                            entry_cost = 0.0;
                            closed++;
                            if (pnl > 0.0) {
                                wins++;
                                gross_win += pnl;
                            } else {
                                gross_loss += -pnl;
                            }
                        }
                    } else if (i >= slow_p && prev_bull == 0 && bull == 1 && cash > 0.0) {
                        double exec_entry = price * (1.0 + fee_rate);
                        double stop_dist = atr_m * atr[i];
                        if (stop_dist <= price * 1e-5) {
                            stop_dist = price * 0.002;
                        }
                        /* Risk-based position sizing (以损定量), capped by max_leverage */
                        double risk_budget = cash * risk_pct;
                        double desired_qty = risk_budget / stop_dist;
                        double max_notional = cash * (max_leverage > 0.0 ? max_leverage : 1.0);
                        double notional = desired_qty * exec_entry;
                        if (notional > max_notional) {
                            notional = max_notional;
                            desired_qty = notional / exec_entry;
                        }
                        if (desired_qty > 0.0 && notional > 1.0) {
                            qty = desired_qty;
                            entry_cost = notional;
                            cash -= notional;
                            stop_price = price - stop_dist;
                        }
                    }

                    double eq = cash + (qty > 0.0 ? qty * price * (1.0 - fee_rate) : 0.0);
                    if (eq > peak_eq) {
                        peak_eq = eq;
                    } else if (peak_eq > 0.0) {
                        double dd = (peak_eq - eq) / peak_eq * 100.0;
                        if (dd > max_dd) max_dd = dd;
                    }
                    prev_bull = bull;
                }

                double final_eq = cash + (qty > 0.0 ? qty * closes[n_candles - 1] * (1.0 - fee_rate) : 0.0);
                double ret_pct = (final_eq - initial_equity) / initial_equity * 100.0;
                double pf = gross_loss > 1e-9 ? (gross_win / gross_loss) : (gross_win > 0.0 ? 99.9 : 0.0);
                double wr = closed > 0 ? ((double)wins / (double)closed * 100.0) : 0.0;

                double *row = &out_metrics[written * MQ_METRICS_STRIDE];
                row[0] = (double)fast_p;
                row[1] = (double)slow_p;
                row[2] = atr_m;
                row[3] = ret_pct;
                row[4] = max_dd;
                row[5] = pf;
                row[6] = wr;
                row[7] = (double)closed;
                written++;
            }
        }
    }

    free(atr);
    return written;
}
