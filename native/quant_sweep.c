#include "quant_sweep.h"
#include <float.h>
#include <math.h>
#include <stdlib.h>
#include <string.h>

#ifdef _WIN32
#include <windows.h>
#include <process.h>
#define MQ_EXPORT __declspec(dllexport)
#else
#include <pthread.h>
#include <unistd.h>
#undef MQ_EXPORT
#define MQ_EXPORT __attribute__((visibility("default")))
#endif

#define MQ_MAX_DATASETS 64
#define MQ_MAX_LOD_LEVELS 20
#define MQ_MIN_LOD_SHIFT 2 /* Level 0 in pyramid = 2^2 = 4 raw bars per bucket */

typedef struct {
    int len;
    int cap;
    float *open;
    float *high;
    float *low;
    float *close;
    float *volume;
} MqLodLevel;

typedef struct {
    int in_use;
    int len;
    int cap;
    int64_t *time_ms;
    double *open;
    double *high;
    double *low;
    double *close;
    double *volume;
    double *atr;
    int n_lod;
    MqLodLevel lod[MQ_MAX_LOD_LEVELS];
} MqDataset;

static MqDataset g_datasets[MQ_MAX_DATASETS];

static float f32_up(double x) {
    float f = (float)x;
    return (double)f < x ? nextafterf(f, FLT_MAX) : f;
}

static float f32_down(double x) {
    float f = (float)x;
    return (double)f > x ? nextafterf(f, -FLT_MAX) : f;
}

MQ_EXPORT int mq_sweep_version(void) {
    return MQ_SWEEP_VERSION;
}

MQ_EXPORT int mq_cpu_threads(void) {
#ifdef _WIN32
    SYSTEM_INFO sysinfo;
    GetSystemInfo(&sysinfo);
    int n = (int)sysinfo.dwNumberOfProcessors;
#else
    int n = (int)sysconf(_SC_NPROCESSORS_ONLN);
#endif
    if (n < 1) n = 1;
    if (n > 16) n = 16;
    return n;
}

static void compute_atr_column(MqDataset *ds) {
    int n = ds->len;
    if (n <= 0) return;
    double tr0 = ds->high[0] - ds->low[0];
    double floor0 = fabs(ds->close[0]) * 0.001;
    if (tr0 < floor0) tr0 = floor0;
    if (tr0 <= 0.0) tr0 = 1e-6;
    ds->atr[0] = tr0;
    double tr_sum = tr0;
    for (int i = 1; i < n; i++) {
        double hl = ds->high[i] - ds->low[i];
        double hc = fabs(ds->high[i] - ds->close[i - 1]);
        double lc = fabs(ds->low[i] - ds->close[i - 1]);
        double tr = hl > hc ? (hl > lc ? hl : lc) : (hc > lc ? hc : lc);
        if (i < MQ_ATR_PERIOD) {
            tr_sum += tr;
            ds->atr[i] = tr_sum / (double)(i + 1);
        } else {
            ds->atr[i] = (ds->atr[i - 1] * (MQ_ATR_PERIOD - 1) + tr) / (double)MQ_ATR_PERIOD;
        }
    }
}

static void free_lod(MqDataset *ds) {
    for (int i = 0; i < ds->n_lod; i++) {
        free(ds->lod[i].open);
        free(ds->lod[i].high);
        free(ds->lod[i].low);
        free(ds->lod[i].close);
        free(ds->lod[i].volume);
        memset(&ds->lod[i], 0, sizeof(MqLodLevel));
    }
    ds->n_lod = 0;
}

static int build_lod_pyramid(MqDataset *ds) {
    free_lod(ds);
    if (ds->len < 8) return 0;

    int raw_cap = ds->cap > ds->len ? ds->cap : ds->len;

    /* Level 0 (shift=2 => 4 raw bars per bucket) */
    int b_len = (ds->len + 3) >> 2;
    int b_cap = (raw_cap + 3) >> 2;
    if (b_cap < b_len) b_cap = b_len;
    MqLodLevel *lv0 = &ds->lod[0];
    lv0->len = b_len;
    lv0->cap = b_cap;
    lv0->open = (float *)malloc((size_t)b_cap * sizeof(float));
    lv0->high = (float *)malloc((size_t)b_cap * sizeof(float));
    lv0->low = (float *)malloc((size_t)b_cap * sizeof(float));
    lv0->close = (float *)malloc((size_t)b_cap * sizeof(float));
    lv0->volume = (float *)malloc((size_t)b_cap * sizeof(float));
    if (!lv0->open || !lv0->high || !lv0->low || !lv0->close || !lv0->volume) {
        free_lod(ds);
        return -1;
    }

    for (int b = 0; b < b_len; b++) {
        int s = b << 2;
        int e = s + 4;
        if (e > ds->len) e = ds->len;
        double hi = ds->high[s];
        double lo = ds->low[s];
        double vol = ds->volume[s];
        for (int p = s + 1; p < e; p++) {
            if (ds->high[p] > hi) hi = ds->high[p];
            if (ds->low[p] < lo) lo = ds->low[p];
            vol += ds->volume[p];
        }
        lv0->open[b] = (float)ds->open[s];
        lv0->high[b] = f32_up(hi);
        lv0->low[b] = f32_down(lo);
        lv0->close[b] = (float)ds->close[e - 1];
        lv0->volume[b] = (float)vol;
    }
    ds->n_lod = 1;

    while (ds->n_lod < MQ_MAX_LOD_LEVELS) {
        MqLodLevel *prev = &ds->lod[ds->n_lod - 1];
        if (prev->cap <= 2 && prev->len <= 2) break;
        int n_b = (prev->len + 1) >> 1;
        int n_cap = (prev->cap + 1) >> 1;
        if (n_cap < n_b) n_cap = n_b;
        if (n_b < 1) break;
        MqLodLevel *cur = &ds->lod[ds->n_lod];
        cur->len = n_b;
        cur->cap = n_cap;
        cur->open = (float *)malloc((size_t)n_cap * sizeof(float));
        cur->high = (float *)malloc((size_t)n_cap * sizeof(float));
        cur->low = (float *)malloc((size_t)n_cap * sizeof(float));
        cur->close = (float *)malloc((size_t)n_cap * sizeof(float));
        cur->volume = (float *)malloc((size_t)n_cap * sizeof(float));
        if (!cur->open || !cur->high || !cur->low || !cur->close || !cur->volume) {
            break;
        }
        for (int b = 0; b < n_b; b++) {
            int c0 = b << 1;
            int c1 = c0 + 1;
            if (c1 < prev->len) {
                cur->open[b] = prev->open[c0];
                cur->high[b] = prev->high[c0] > prev->high[c1] ? prev->high[c0] : prev->high[c1];
                cur->low[b] = prev->low[c0] < prev->low[c1] ? prev->low[c0] : prev->low[c1];
                cur->close[b] = prev->close[c1];
                cur->volume[b] = (float)((double)prev->volume[c0] + (double)prev->volume[c1]);
            } else {
                cur->open[b] = prev->open[c0];
                cur->high[b] = prev->high[c0];
                cur->low[b] = prev->low[c0];
                cur->close[b] = prev->close[c0];
                cur->volume[b] = prev->volume[c0];
            }
        }
        ds->n_lod++;
        if (cur->len <= 2 && cur->cap <= 4) break;
    }
    return 0;
}

static int reserve_dataset_slot(int initial_len, int initial_cap) {
    if (initial_len < 0) return -1;
    int slot = -1;
    for (int i = 1; i < MQ_MAX_DATASETS; i++) {
        if (!g_datasets[i].in_use) {
            slot = i;
            break;
        }
    }
    if (slot < 0) return -2;

    MqDataset *ds = &g_datasets[slot];
    memset(ds, 0, sizeof(MqDataset));
    int cap = initial_cap > initial_len + 256 ? initial_cap : (initial_len + 256);
    if (cap < 256) cap = 256;

    ds->time_ms = (int64_t *)malloc((size_t)cap * sizeof(int64_t));
    ds->open = (double *)malloc((size_t)cap * sizeof(double));
    ds->high = (double *)malloc((size_t)cap * sizeof(double));
    ds->low = (double *)malloc((size_t)cap * sizeof(double));
    ds->close = (double *)malloc((size_t)cap * sizeof(double));
    ds->volume = (double *)malloc((size_t)cap * sizeof(double));
    ds->atr = (double *)malloc((size_t)cap * sizeof(double));
    if (!ds->time_ms || !ds->open || !ds->high || !ds->low || !ds->close || !ds->volume || !ds->atr) {
        mq_dataset_free(slot);
        return -3;
    }
    ds->in_use = 1;
    ds->len = initial_len;
    ds->cap = cap;
    return slot;
}

static int ensure_dataset_capacity(MqDataset *ds, int needed_len) {
    if (needed_len <= ds->cap) return 0;
    int new_cap = ds->cap < 1024 ? 1024 : ds->cap;
    while (new_cap < needed_len) {
        new_cap = new_cap < (1 << 20) ? (new_cap * 2) : (new_cap + (1 << 19));
    }

    int64_t *t = (int64_t *)realloc(ds->time_ms, (size_t)new_cap * sizeof(int64_t));
    if (!t) return -1;
    ds->time_ms = t;

    double *o = (double *)realloc(ds->open, (size_t)new_cap * sizeof(double));
    if (!o) return -1;
    ds->open = o;

    double *h = (double *)realloc(ds->high, (size_t)new_cap * sizeof(double));
    if (!h) return -1;
    ds->high = h;

    double *l = (double *)realloc(ds->low, (size_t)new_cap * sizeof(double));
    if (!l) return -1;
    ds->low = l;

    double *c = (double *)realloc(ds->close, (size_t)new_cap * sizeof(double));
    if (!c) return -1;
    ds->close = c;

    double *v = (double *)realloc(ds->volume, (size_t)new_cap * sizeof(double));
    if (!v) return -1;
    ds->volume = v;

    double *a = (double *)realloc(ds->atr, (size_t)new_cap * sizeof(double));
    if (!a) return -1;
    ds->atr = a;

    ds->cap = new_cap;
    return 1; /* 1 indicates capacity was expanded */
}

static MqDataset *get_dataset(int ds_id) {
    if (ds_id <= 0 || ds_id >= MQ_MAX_DATASETS || !g_datasets[ds_id].in_use) {
        return NULL;
    }
    return &g_datasets[ds_id];
}

MQ_EXPORT void mq_dataset_free(int ds_id) {
    if (ds_id <= 0 || ds_id >= MQ_MAX_DATASETS) return;
    MqDataset *ds = &g_datasets[ds_id];
    free_lod(ds);
    free(ds->time_ms);
    free(ds->open);
    free(ds->high);
    free(ds->low);
    free(ds->close);
    free(ds->volume);
    free(ds->atr);
    memset(ds, 0, sizeof(MqDataset));
}

MQ_EXPORT int mq_dataset_alloc(
    const int64_t *open_time_ms,
    const double *opens,
    const double *highs,
    const double *lows,
    const double *closes,
    const double *volumes,
    int n_candles
) {
    if (!opens || !highs || !lows || !closes || n_candles <= 0) return -1;
    int slot = reserve_dataset_slot(n_candles, n_candles + 256);
    if (slot < 0) return slot;
    MqDataset *ds = &g_datasets[slot];
    size_t bytes = (size_t)n_candles * sizeof(double);
    if (open_time_ms) {
        memcpy(ds->time_ms, open_time_ms, (size_t)n_candles * sizeof(int64_t));
    } else {
        for (int i = 0; i < n_candles; i++) ds->time_ms[i] = (int64_t)i * 60000LL;
    }
    memcpy(ds->open, opens, bytes);
    memcpy(ds->high, highs, bytes);
    memcpy(ds->low, lows, bytes);
    memcpy(ds->close, closes, bytes);
    if (volumes) {
        memcpy(ds->volume, volumes, bytes);
    } else {
        for (int i = 0; i < n_candles; i++) ds->volume[i] = 1.0;
    }
    compute_atr_column(ds);
    build_lod_pyramid(ds);
    return slot;
}

MQ_EXPORT int mq_dataset_create_empty(int initial_cap) {
    return reserve_dataset_slot(0, initial_cap > 256 ? initial_cap : 4096);
}

MQ_EXPORT int mq_dataset_append_batch(
    int ds_id,
    const int64_t *open_time_ms,
    const double *opens,
    const double *highs,
    const double *lows,
    const double *closes,
    const double *volumes,
    int n_candles
) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds || !opens || !highs || !lows || !closes || n_candles <= 0) return -1;
    if (ensure_dataset_capacity(ds, ds->len + n_candles) < 0) return -2;

    int base = ds->len;
    size_t bytes = (size_t)n_candles * sizeof(double);
    if (open_time_ms) {
        memcpy(&ds->time_ms[base], open_time_ms, (size_t)n_candles * sizeof(int64_t));
    } else {
        int64_t t0 = base > 0 ? (ds->time_ms[base - 1] + 60000LL) : 0LL;
        for (int i = 0; i < n_candles; i++) ds->time_ms[base + i] = t0 + (int64_t)i * 60000LL;
    }
    memcpy(&ds->open[base], opens, bytes);
    memcpy(&ds->high[base], highs, bytes);
    memcpy(&ds->low[base], lows, bytes);
    memcpy(&ds->close[base], closes, bytes);
    if (volumes) {
        memcpy(&ds->volume[base], volumes, bytes);
    } else {
        for (int i = 0; i < n_candles; i++) ds->volume[base + i] = 1.0;
    }
    ds->len += n_candles;
    return ds->len;
}

MQ_EXPORT int mq_dataset_finalize(int ds_id) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds) return -1;
    compute_atr_column(ds);
    build_lod_pyramid(ds);
    return ds->len;
}

static inline uint64_t splitmix64(uint64_t *state) {
    uint64_t z = (*state += 0x9e3779b97f4a7c15ULL);
    z = (z ^ (z >> 30)) * 0xbf58476d1ce4e5b9ULL;
    z = (z ^ (z >> 27)) * 0x94d049bb133111ebULL;
    return z ^ (z >> 31);
}

static inline double rand_uniform(uint64_t *state) {
    return (double)(splitmix64(state) >> 11) * (1.0 / 9007199254740992.0);
}

MQ_EXPORT int mq_dataset_generate_mock(
    int n_candles,
    double base_price,
    double bar_volatility,
    int64_t start_time_ms,
    int64_t step_ms,
    uint64_t seed
) {
    if (n_candles < 30 || base_price <= 0.0 || bar_volatility <= 0.0) return -1;
    int slot = reserve_dataset_slot(n_candles, n_candles + 256);
    if (slot < 0) return slot;
    MqDataset *ds = &g_datasets[slot];

    uint64_t rng = seed ? seed : 0x4d795175616e74ULL;
    double price = base_price;
    double vol_cluster = 1.0;
    double trend_drift = 0.0;

    for (int i = 0; i < n_candles; i++) {
        /* GARCH-like volatility clustering + multi-horizon regime shifts */
        if ((i & 255) == 0) {
            double r_reg = rand_uniform(&rng) * 2.0 - 1.0;
            trend_drift = r_reg * bar_volatility * 0.065;
        }
        double u1 = rand_uniform(&rng) * 2.0 - 1.0;
        double u2 = rand_uniform(&rng) * 2.0 - 1.0;
        double shock = (u1 + u2) * 0.70710678; /* fast zero-mean bell-shaped shock */
        vol_cluster = 0.94 * vol_cluster + 0.06 * (0.5 + fabs(shock) * 1.15);

        double cur_vol = bar_volatility * vol_cluster;
        double ret = trend_drift + cur_vol * shock;
        double o = price;
        double c = o * (1.0 + ret);
        if (c < base_price * 0.08) {
            c = base_price * 0.08;
            trend_drift = fabs(trend_drift) + bar_volatility * 0.04;
        }
        double wick_hi = rand_uniform(&rng) * cur_vol * 0.85 * o;
        double wick_lo = rand_uniform(&rng) * cur_vol * 0.85 * o;
        double hi = (o > c ? o : c) + wick_hi;
        double lo = (o < c ? o : c) - wick_lo;
        if (lo <= 0.0) lo = c * 0.5;

        ds->time_ms[i] = start_time_ms + (int64_t)i * step_ms;
        ds->open[i] = o;
        ds->high[i] = hi;
        ds->low[i] = lo;
        ds->close[i] = c;
        ds->volume[i] = 50.0 + fabs(shock) * 380.0 * vol_cluster;
        price = c;
    }

    compute_atr_column(ds);
    build_lod_pyramid(ds);
    return slot;
}

MQ_EXPORT int mq_dataset_count(int ds_id) {
    MqDataset *ds = get_dataset(ds_id);
    return ds ? ds->len : 0;
}

MQ_EXPORT int64_t mq_dataset_memory_bytes(int ds_id) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds) return 0;
    int64_t total = (int64_t)ds->cap * (sizeof(int64_t) + 6 * sizeof(double));
    for (int i = 0; i < ds->n_lod; i++) {
        total += (int64_t)ds->lod[i].cap * 5 * sizeof(float);
    }
    return total;
}

MQ_EXPORT int mq_dataset_get_last(
    int ds_id,
    int64_t *out_open_time_ms,
    double *out_ohlcv
) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds || ds->len <= 0) return -1;
    int idx = ds->len - 1;
    if (out_open_time_ms) *out_open_time_ms = ds->time_ms[idx];
    if (out_ohlcv) {
        out_ohlcv[0] = ds->open[idx];
        out_ohlcv[1] = ds->high[idx];
        out_ohlcv[2] = ds->low[idx];
        out_ohlcv[3] = ds->close[idx];
        out_ohlcv[4] = ds->volume[idx];
    }
    return ds->len;
}

/* O(log N) live bar update up the rightmost spine of the LOD pyramid */
MQ_EXPORT int mq_dataset_update_last(
    int ds_id,
    int64_t open_time_ms,
    double open,
    double high,
    double low,
    double close,
    double volume
) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds) return -1;

    int grew = 0;
    int idx = 0;
    if (ds->len == 0) {
        if (ensure_dataset_capacity(ds, 1) < 0) return -2;
        idx = ds->len++;
        grew = 1;
    } else {
        idx = ds->len - 1;
        if (open_time_ms > ds->time_ms[idx]) {
            int rc = ensure_dataset_capacity(ds, ds->len + 1);
            if (rc < 0) return -2;
            if (rc > 0) grew = 1;
            idx = ds->len++;
        }
    }

    ds->time_ms[idx] = open_time_ms;
    ds->open[idx] = open;
    ds->high[idx] = high;
    ds->low[idx] = low;
    ds->close[idx] = close;
    ds->volume[idx] = volume;

    if (idx == 0) {
        double tr0 = high - low;
        double floor0 = fabs(close) * 0.001;
        if (tr0 < floor0) tr0 = floor0;
        if (tr0 <= 0.0) tr0 = 1e-6;
        ds->atr[0] = tr0;
    } else {
        double hl = high - low;
        double hc = fabs(high - ds->close[idx - 1]);
        double lc = fabs(low - ds->close[idx - 1]);
        double tr = hl > hc ? (hl > lc ? hl : lc) : (hc > lc ? hc : lc);
        if (idx < MQ_ATR_PERIOD) {
            double tr_sum = ds->atr[0];
            for (int j = 1; j <= idx; j++) {
                double jhl = ds->high[j] - ds->low[j];
                double jhc = fabs(ds->high[j] - ds->close[j - 1]);
                double jlc = fabs(ds->low[j] - ds->close[j - 1]);
                tr_sum += jhl > jhc ? (jhl > jlc ? jhl : jlc) : (jhc > jlc ? jhc : jlc);
            }
            ds->atr[idx] = tr_sum / (double)(idx + 1);
        } else {
            ds->atr[idx] = (ds->atr[idx - 1] * (MQ_ATR_PERIOD - 1) + tr) / (double)MQ_ATR_PERIOD;
        }
    }

    int b = idx >> 2;
    if (grew || (ds->len >= 8 && ds->n_lod == 0) || (ds->n_lod > 0 && b >= ds->lod[0].cap)) {
        build_lod_pyramid(ds);
        return ds->len;
    }

    /* Update LOD buckets along the rightmost spine in O(log N) */
    if (ds->n_lod > 0 && b < ds->lod[0].cap) {
        if (b >= ds->lod[0].len) ds->lod[0].len = b + 1;
        int s = b << 2;
        int e = s + 4;
        if (e > ds->len) e = ds->len;
        double hi = ds->high[s], lo = ds->low[s], vol = ds->volume[s];
        for (int p = s + 1; p < e; p++) {
            if (ds->high[p] > hi) hi = ds->high[p];
            if (ds->low[p] < lo) lo = ds->low[p];
            vol += ds->volume[p];
        }
        ds->lod[0].open[b] = (float)ds->open[s];
        ds->lod[0].high[b] = f32_up(hi);
        ds->lod[0].low[b] = f32_down(lo);
        ds->lod[0].close[b] = (float)ds->close[e - 1];
        ds->lod[0].volume[b] = (float)vol;

        for (int lv = 1; lv < ds->n_lod; lv++) {
            b >>= 1;
            if (b >= ds->lod[lv].cap) {
                build_lod_pyramid(ds);
                break;
            }
            if (b >= ds->lod[lv].len) ds->lod[lv].len = b + 1;
            MqLodLevel *prev = &ds->lod[lv - 1];
            MqLodLevel *cur = &ds->lod[lv];
            int c0 = b << 1, c1 = c0 + 1;
            if (c1 < prev->len) {
                cur->open[b] = prev->open[c0];
                cur->high[b] = prev->high[c0] > prev->high[c1] ? prev->high[c0] : prev->high[c1];
                cur->low[b] = prev->low[c0] < prev->low[c1] ? prev->low[c0] : prev->low[c1];
                cur->close[b] = prev->close[c1];
                cur->volume[b] = (float)((double)prev->volume[c0] + (double)prev->volume[c1]);
            } else {
                cur->open[b] = prev->open[c0];
                cur->high[b] = prev->high[c0];
                cur->low[b] = prev->low[c0];
                cur->close[b] = prev->close[c0];
                cur->volume[b] = prev->volume[c0];
            }
        }
    }
    return ds->len;
}

/* Segment-tree style O(log N) min/max query + LOD bucket slice extraction */
static void query_extremes_lod(const MqDataset *ds, int from_idx, int to_idx, double *out_lo, double *out_hi) {
    double lo = DBL_MAX, hi = -DBL_MAX;
    int a = from_idx < 0 ? 0 : from_idx;
    int b = to_idx > ds->len ? ds->len : to_idx;
    if (a >= b) {
        *out_lo = 0.0;
        *out_hi = 1.0;
        return;
    }
    int a4 = (a + 3) & ~3;
    int b4 = b & ~3;
    if (ds->n_lod == 0 || a4 >= b4) {
        for (int i = a; i < b; i++) {
            if (ds->low[i] < lo) lo = ds->low[i];
            if (ds->high[i] > hi) hi = ds->high[i];
        }
        *out_lo = lo;
        *out_hi = hi;
        return;
    }
    for (int i = a; i < a4; i++) {
        if (ds->low[i] < lo) lo = ds->low[i];
        if (ds->high[i] > hi) hi = ds->high[i];
    }
    for (int i = b4; i < b; i++) {
        if (ds->low[i] < lo) lo = ds->low[i];
        if (ds->high[i] > hi) hi = ds->high[i];
    }
    int l = a4 >> 2;
    int r = b4 >> 2;
    for (int lv = 0; lv < ds->n_lod; lv++) {
        const MqLodLevel *L = &ds->lod[lv];
        if (lv + 1 == ds->n_lod) {
            for (int x = l; x < r && x < L->len; x++) {
                if ((double)L->low[x] < lo) lo = (double)L->low[x];
                if ((double)L->high[x] > hi) hi = (double)L->high[x];
            }
            break;
        }
        if (l & 1) {
            if (l < L->len) {
                if ((double)L->low[l] < lo) lo = (double)L->low[l];
                if ((double)L->high[l] > hi) hi = (double)L->high[l];
            }
            l++;
        }
        if (r & 1) {
            r--;
            if (r < L->len) {
                if ((double)L->low[r] < lo) lo = (double)L->low[r];
                if ((double)L->high[r] > hi) hi = (double)L->high[r];
            }
        }
        if (l >= r) break;
        l >>= 1;
        r >>= 1;
    }
    *out_lo = lo;
    *out_hi = hi;
}

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
) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds || max_out <= 0 || !out_xs || !out_closes) return -1;
    int a = from_idx < 0 ? 0 : from_idx;
    int b = to_idx > ds->len ? ds->len : to_idx;
    if (a >= b) return 0;

    if (out_extremes) {
        query_extremes_lod(ds, a, b, &out_extremes[0], &out_extremes[1]);
    }

    int span = b - a;
    if (span <= max_out || ds->n_lod == 0) {
        int step = span <= max_out ? 1 : (span + max_out - 1) / max_out;
        int k = 0;
        for (int i = a; i < b && k < max_out; i += step) {
            out_xs[k] = (double)i;
            out_closes[k] = ds->close[i];
            if (out_highs) out_highs[k] = ds->high[i];
            if (out_lows) out_lows[k] = ds->low[i];
            k++;
        }
        return k;
    }

    /* Choose lowest LOD level whose bucket count in [a, b) fits max_out */
    int chosen_lv = 0;
    for (int lv = 0; lv < ds->n_lod; lv++) {
        int shift = lv + MQ_MIN_LOD_SHIFT;
        int buckets = (span + (1 << shift) - 1) >> shift;
        chosen_lv = lv;
        if (buckets <= max_out) break;
    }

    int shift = chosen_lv + MQ_MIN_LOD_SHIFT;
    const MqLodLevel *L = &ds->lod[chosen_lv];
    int b_start = a >> shift;
    int b_end = (b + (1 << shift) - 1) >> shift;
    if (b_end > L->len) b_end = L->len;
    int b_span = b_end - b_start;
    int b_step = b_span <= max_out ? 1 : (b_span + max_out - 1) / max_out;

    int k = 0;
    for (int bi = b_start; bi < b_end && k < max_out; bi += b_step) {
        out_xs[k] = (double)(bi << shift);
        out_closes[k] = (double)L->close[bi];
        if (out_highs) out_highs[k] = (double)L->high[bi];
        if (out_lows) out_lows[k] = (double)L->low[bi];
        k++;
    }
    return k;
}

/* ---- Zero-Copy Full Backtest on Resident SoA Dataset (Long / Short / Both) ---- */

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
    double swap_long_rate,
    double swap_short_rate,
    double *out_summary,
    double *out_xs,
    double *out_strategy_ys,
    double *out_hold_ys,
    double *out_drawdown_ys,
    int max_curve_pts,
    double *out_trades,
    int max_trades
) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds || !out_summary || ds->len < 30 || fast_period < 2 || slow_period <= fast_period || initial_equity <= 0.0) {
        return -1;
    }

    const int n = ds->len;
    const double *highs = ds->high;
    const double *lows = ds->low;
    const double *closes = ds->close;
    const double *atr = ds->atr;
    const int64_t *times = ds->time_ms;
    const int dir_mode = direction > 0 ? 1 : (direction < 0 ? -1 : 0);
    const double max_lev = max_leverage > 0.1 ? max_leverage : 0.1;

    double k_fast = 2.0 / ((double)fast_period + 1.0);
    double k_slow = 2.0 / ((double)slow_period + 1.0);
    double ema_f = closes[0];
    double ema_s = closes[0];
    int prev_bull = 0;

    double balance = initial_equity;
    int pos_dir = 0; /* 0: flat, 1: long, -1: short */
    double qty = 0.0;
    double entry_price = 0.0;
    double entry_fee = 0.0;
    double pos_swap = 0.0;
    double risk_dollars = 0.0;
    double stop_price = 0.0;
    int64_t entry_time_ms = 0;

    double peak_eq = initial_equity;
    double max_dd = 0.0;
    double gross_win = 0.0;
    double gross_loss = 0.0;
    double total_fees = 0.0;
    double r_sum = 0.0;
    int wins = 0;
    int closed = 0;

    /* Ring buffer for the latest `max_trades` trades */
    int trade_ring_cap = max_trades > 0 && out_trades ? max_trades : 0;
    double *trade_ring = trade_ring_cap > 0 ? (double *)malloc((size_t)trade_ring_cap * MQ_TRADE_STRIDE * sizeof(double)) : NULL;

    int curve_stride = max_curve_pts > 1 ? ((n - 1) / (max_curve_pts - 1)) : n;
    if (curve_stride < 1) curve_stride = 1;
    int curve_len = 0;
    double first_close = closes[0] > 1e-9 ? closes[0] : 1.0;

    if (max_curve_pts > 0 && out_xs && out_strategy_ys && out_hold_ys && out_drawdown_ys) {
        out_xs[0] = 0.0;
        out_strategy_ys[0] = initial_equity;
        out_hold_ys[0] = initial_equity;
        out_drawdown_ys[0] = 0.0;
        curve_len = 1;
    }

    for (int i = 1; i < n; i++) {
        double price = closes[i];
        ema_f = (price - ema_f) * k_fast + ema_f;
        ema_s = (price - ema_s) * k_slow + ema_s;
        int bull = ema_f > ema_s ? 1 : 0;
        int cross_bull = (prev_bull == 0 && bull == 1) ? 1 : 0;
        int cross_bear = (prev_bull == 1 && bull == 0) ? 1 : 0;

        /* 1. Manage / close existing position */
        if (pos_dir != 0) {
            if (times) {
                int64_t prev_day = times[i - 1] / 86400000LL;
                int64_t cur_day = times[i] / 86400000LL;
                if (cur_day > prev_day) {
                    double days = (double)(cur_day - prev_day);
                    double s_rate = pos_dir == 1 ? swap_long_rate : swap_short_rate;
                    if (s_rate != 0.0) {
                        double swap_cost = qty * price * s_rate * days;
                        pos_swap += swap_cost;
                        total_fees += swap_cost;
                    }
                }
            }

            int hit_stop = 0;
            int flip_exit = 0;
            double raw_exit = price;

            if (pos_dir == 1) {
                double cand_stop = price - atr_mult * atr[i];
                if (cand_stop > stop_price) stop_price = cand_stop;
                hit_stop = lows[i] <= stop_price ? 1 : 0;
                flip_exit = cross_bear;
                if (hit_stop) {
                    /* Gap-down fill: cannot fill better than current bar high */
                    raw_exit = stop_price < highs[i] ? stop_price : highs[i];
                }
            } else {
                double cand_stop = price + atr_mult * atr[i];
                if (cand_stop < stop_price) stop_price = cand_stop;
                hit_stop = highs[i] >= stop_price ? 1 : 0;
                flip_exit = cross_bull;
                if (hit_stop) {
                    /* Gap-up fill: cannot fill better than current bar low */
                    raw_exit = stop_price > lows[i] ? stop_price : lows[i];
                }
            }

            if (hit_stop || flip_exit) {
                double exit_fee = qty * raw_exit * fee_rate;
                double pnl = pos_dir == 1
                    ? (qty * (raw_exit - entry_price) - entry_fee - exit_fee - pos_swap)
                    : (qty * (entry_price - raw_exit) - entry_fee - exit_fee - pos_swap);
                total_fees += exit_fee;
                double r_mult = risk_dollars > 1e-6 ? (pnl / risk_dollars) : 0.0;
                r_sum += r_mult;

                if (pnl > 0.0) {
                    wins++;
                    gross_win += pnl;
                } else {
                    gross_loss += -pnl;
                }

                if (trade_ring) {
                    int slot = (closed % trade_ring_cap) * MQ_TRADE_STRIDE;
                    double reason_code = (double)pos_dir * (hit_stop ? 1.0 : 2.0);
                    trade_ring[slot + 0] = (double)(closed + 1);
                    trade_ring[slot + 1] = (double)entry_time_ms;
                    trade_ring[slot + 2] = (double)times[i];
                    trade_ring[slot + 3] = entry_price;
                    trade_ring[slot + 4] = raw_exit;
                    trade_ring[slot + 5] = pnl;
                    trade_ring[slot + 6] = r_mult;
                    trade_ring[slot + 7] = reason_code;
                }
                closed++;
                balance += pnl;
                if (balance < 0.0) balance = 0.0;
                pos_dir = 0;
                qty = 0.0;
                pos_swap = 0.0;
            }
        }

        /* 2. Open new position (or reverse immediately on opposite crossover in MQ_DIR_BOTH mode) */
        if (pos_dir == 0 && i >= slow_period && balance > 0.0) {
            int want_dir = 0;
            if (cross_bull && dir_mode >= 0) want_dir = 1;
            else if (cross_bear && dir_mode <= 0) want_dir = -1;

            if (want_dir != 0) {
                double exec_entry = price * (1.0 + fee_rate);
                double stop_dist = atr_mult * atr[i];
                if (stop_dist < price * 0.002) stop_dist = price * 0.002;
                double desired_qty = (balance * risk_pct) / stop_dist;
                double max_notional = balance * max_lev;
                double notional = desired_qty * exec_entry;
                if (notional > max_notional) {
                    notional = max_notional;
                    desired_qty = notional / exec_entry;
                }
                if (desired_qty > 0.0 && notional > 1.0) {
                    pos_dir = want_dir;
                    qty = desired_qty;
                    entry_price = price;
                    entry_time_ms = times[i];
                    entry_fee = desired_qty * price * fee_rate;
                    pos_swap = 0.0;
                    total_fees += entry_fee;
                    risk_dollars = desired_qty * stop_dist;
                    if (risk_dollars < 1.0) risk_dollars = 1.0;
                    stop_price = want_dir == 1 ? (price - stop_dist) : (price + stop_dist);
                }
            }
        }

        double eq = balance;
        if (pos_dir == 1) {
            eq = balance + qty * (price - entry_price) - entry_fee - qty * price * fee_rate - pos_swap;
        } else if (pos_dir == -1) {
            eq = balance + qty * (entry_price - price) - entry_fee - qty * price * fee_rate - pos_swap;
        }
        if (eq < 0.0) eq = 0.0;

        if (eq > peak_eq) peak_eq = eq;
        double dd = peak_eq > 0.0 ? -(peak_eq - eq) / peak_eq * 100.0 : 0.0;
        if (-dd > max_dd) max_dd = -dd;

        if (curve_len < max_curve_pts && out_xs && ((i % curve_stride == 0) || i == n - 1)) {
            double bh = initial_equity * (price / first_close);
            out_xs[curve_len] = (double)i;
            out_strategy_ys[curve_len] = eq;
            out_hold_ys[curve_len] = bh;
            out_drawdown_ys[curve_len] = dd;
            curve_len++;
        }

        prev_bull = bull;
    }

    double last_p = closes[n - 1];
    double final_eq = balance;
    if (pos_dir == 1) {
        final_eq = balance + qty * (last_p - entry_price) - entry_fee - qty * last_p * fee_rate - pos_swap;
    } else if (pos_dir == -1) {
        final_eq = balance + qty * (entry_price - last_p) - entry_fee - qty * last_p * fee_rate - pos_swap;
    }
    if (final_eq < 0.0) final_eq = 0.0;

    double net_ret = (final_eq - initial_equity) / initial_equity * 100.0;
    double bh_ret = (initial_equity * (last_p / first_close) - initial_equity) / initial_equity * 100.0;
    double pf = gross_loss > 1e-6 ? (gross_win / gross_loss) : (gross_win > 0.0 ? 99.9 : 0.0);
    double wr = closed > 0 ? ((double)wins / (double)closed * 100.0) : 0.0;
    double exp_r = closed > 0 ? (r_sum / (double)closed) : 0.0;
    double calmar = max_dd > 0.05 ? (net_ret / max_dd) : (net_ret > 0.0 ? 9.9 : 0.0);

    int exported_trades = 0;
    if (trade_ring && out_trades) {
        exported_trades = closed < trade_ring_cap ? closed : trade_ring_cap;
        /* Copy newest first */
        for (int k = 0; k < exported_trades; k++) {
            int idx = (closed - 1 - k) % trade_ring_cap;
            memcpy(&out_trades[k * MQ_TRADE_STRIDE], &trade_ring[idx * MQ_TRADE_STRIDE], MQ_TRADE_STRIDE * sizeof(double));
        }
    }
    free(trade_ring);

    out_summary[0] = final_eq;
    out_summary[1] = net_ret;
    out_summary[2] = bh_ret;
    out_summary[3] = max_dd;
    out_summary[4] = calmar;
    out_summary[5] = pf;
    out_summary[6] = wr;
    out_summary[7] = exp_r;
    out_summary[8] = (double)closed;
    out_summary[9] = total_fees;
    out_summary[10] = gross_win;
    out_summary[11] = gross_loss;
    out_summary[12] = (double)curve_len;
    out_summary[13] = (double)exported_trades;
    return curve_len;
}

/* ---- Multi-Threaded Lock-Free Grid Sweep Kernel ---- */

typedef struct {
    int fast_p;
    int slow_p;
    double atr_m;
} MqComboTask;

typedef struct {
    const int64_t *times;
    const double *highs;
    const double *lows;
    const double *closes;
    const double *atr;
    int n_candles;
    const MqComboTask *tasks;
    int start_task;
    int end_task;
    double initial_equity;
    double fee_rate;
    double risk_pct;
    double max_leverage;
    int direction;
    double swap_long_rate;
    double swap_short_rate;
    double *out_metrics;
} MqSweepWorkerArgs;

static void eval_combo_slice(MqSweepWorkerArgs *w) {
    const int n = w->n_candles;
    const int64_t *times = w->times;
    const double *highs = w->highs;
    const double *lows = w->lows;
    const double *closes = w->closes;
    const double *atr = w->atr;
    const double fee_rate = w->fee_rate;
    const double risk_pct = w->risk_pct;
    const double max_lev = w->max_leverage > 0.1 ? w->max_leverage : 0.1;
    const double init_eq = w->initial_equity;
    const int dir_mode = w->direction > 0 ? 1 : (w->direction < 0 ? -1 : 0);
    const double swap_long_rate = w->swap_long_rate;
    const double swap_short_rate = w->swap_short_rate;

    for (int t = w->start_task; t < w->end_task; t++) {
        int fast_p = w->tasks[t].fast_p;
        int slow_p = w->tasks[t].slow_p;
        double atr_m = w->tasks[t].atr_m;

        double k_fast = 2.0 / ((double)fast_p + 1.0);
        double k_slow = 2.0 / ((double)slow_p + 1.0);

        double balance = init_eq;
        int pos_dir = 0;
        double qty = 0.0;
        double entry_price = 0.0;
        double entry_fee = 0.0;
        double pos_swap = 0.0;
        double stop_price = 0.0;
        double peak_eq = init_eq;
        double max_dd = 0.0;
        double gross_win = 0.0;
        double gross_loss = 0.0;
        int wins = 0;
        int closed = 0;

        double ema_f = closes[0];
        double ema_s = closes[0];
        int prev_bull = 0;

        for (int i = 1; i < n; i++) {
            double price = closes[i];
            ema_f = (price - ema_f) * k_fast + ema_f;
            ema_s = (price - ema_s) * k_slow + ema_s;
            int bull = ema_f > ema_s ? 1 : 0;
            int cross_bull = (prev_bull == 0 && bull == 1) ? 1 : 0;
            int cross_bear = (prev_bull == 1 && bull == 0) ? 1 : 0;

            if (pos_dir != 0) {
                if (times) {
                    int64_t prev_day = times[i - 1] / 86400000LL;
                    int64_t cur_day = times[i] / 86400000LL;
                    if (cur_day > prev_day) {
                        double days = (double)(cur_day - prev_day);
                        double s_rate = pos_dir == 1 ? swap_long_rate : swap_short_rate;
                        if (s_rate != 0.0) {
                            pos_swap += qty * price * s_rate * days;
                        }
                    }
                }

                int hit_stop = 0;
                int flip_exit = 0;
                double raw_exit = price;

                if (pos_dir == 1) {
                    double candidate_stop = price - atr_m * atr[i];
                    if (candidate_stop > stop_price) stop_price = candidate_stop;
                    hit_stop = lows[i] <= stop_price ? 1 : 0;
                    flip_exit = cross_bear;
                    if (hit_stop) {
                        raw_exit = stop_price < highs[i] ? stop_price : highs[i];
                    }
                } else {
                    double candidate_stop = price + atr_m * atr[i];
                    if (candidate_stop < stop_price) stop_price = candidate_stop;
                    hit_stop = highs[i] >= stop_price ? 1 : 0;
                    flip_exit = cross_bull;
                    if (hit_stop) {
                        raw_exit = stop_price > lows[i] ? stop_price : lows[i];
                    }
                }

                if (hit_stop || flip_exit) {
                    double exit_fee = qty * raw_exit * fee_rate;
                    double pnl = pos_dir == 1
                        ? (qty * (raw_exit - entry_price) - entry_fee - exit_fee - pos_swap)
                        : (qty * (entry_price - raw_exit) - entry_fee - exit_fee - pos_swap);
                    balance += pnl;
                    if (balance < 0.0) balance = 0.0;
                    pos_dir = 0;
                    qty = 0.0;
                    pos_swap = 0.0;
                    closed++;
                    if (pnl > 0.0) {
                        wins++;
                        gross_win += pnl;
                    } else {
                        gross_loss += -pnl;
                    }
                }
            }

            if (pos_dir == 0 && i >= slow_p && balance > 0.0) {
                int want_dir = 0;
                if (cross_bull && dir_mode >= 0) want_dir = 1;
                else if (cross_bear && dir_mode <= 0) want_dir = -1;

                if (want_dir != 0) {
                    double exec_entry = price * (1.0 + fee_rate);
                    double stop_dist = atr_m * atr[i];
                    if (stop_dist < price * 0.002) stop_dist = price * 0.002;
                    double desired_qty = (balance * risk_pct) / stop_dist;
                    double max_notional = balance * max_lev;
                    double notional = desired_qty * exec_entry;
                    if (notional > max_notional) {
                        notional = max_notional;
                        desired_qty = notional / exec_entry;
                    }
                    if (desired_qty > 0.0 && notional > 1.0) {
                        pos_dir = want_dir;
                        qty = desired_qty;
                        entry_price = price;
                        entry_fee = desired_qty * price * fee_rate;
                        pos_swap = 0.0;
                        stop_price = want_dir == 1 ? (price - stop_dist) : (price + stop_dist);
                    }
                }
            }

            double eq = balance;
            if (pos_dir == 1) {
                eq = balance + qty * (price - entry_price) - entry_fee - qty * price * fee_rate - pos_swap;
            } else if (pos_dir == -1) {
                eq = balance + qty * (entry_price - price) - entry_fee - qty * price * fee_rate - pos_swap;
            }
            if (eq < 0.0) eq = 0.0;

            if (eq > peak_eq) {
                peak_eq = eq;
            } else if (peak_eq > 0.0) {
                double dd = (peak_eq - eq) / peak_eq * 100.0;
                if (dd > max_dd) max_dd = dd;
            }
            prev_bull = bull;
        }

        double last_p = closes[n - 1];
        double final_eq = balance;
        if (pos_dir == 1) {
            final_eq = balance + qty * (last_p - entry_price) - entry_fee - qty * last_p * fee_rate - pos_swap;
        } else if (pos_dir == -1) {
            final_eq = balance + qty * (entry_price - last_p) - entry_fee - qty * last_p * fee_rate - pos_swap;
        }
        if (final_eq < 0.0) final_eq = 0.0;

        double ret_pct = (final_eq - init_eq) / init_eq * 100.0;
        double pf = gross_loss > 1e-6 ? (gross_win / gross_loss) : (gross_win > 0.0 ? 99.9 : 0.0);
        double wr = closed > 0 ? ((double)wins / (double)closed * 100.0) : 0.0;

        double *row = &w->out_metrics[t * MQ_METRICS_STRIDE];
        row[0] = (double)fast_p;
        row[1] = (double)slow_p;
        row[2] = atr_m;
        row[3] = ret_pct;
        row[4] = max_dd;
        row[5] = pf;
        row[6] = wr;
        row[7] = (double)closed;
    }
}

#ifdef _WIN32
static unsigned __stdcall win_worker_entry(void *arg) {
    eval_combo_slice((MqSweepWorkerArgs *)arg);
    return 0;
}
#else
static void *posix_worker_entry(void *arg) {
    eval_combo_slice((MqSweepWorkerArgs *)arg);
    return NULL;
}
#endif

static int run_parallel_sweep(
    const int64_t *times,
    const double *highs,
    const double *lows,
    const double *closes,
    const double *atr,
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
    int direction,
    double swap_long_rate,
    double swap_short_rate,
    int n_threads,
    double *out_metrics,
    int max_combos
) {
    int cap_tasks = n_fast * n_slow * n_atr;
    if (cap_tasks > max_combos) cap_tasks = max_combos;
    if (cap_tasks <= 0) return 0;

    MqComboTask *tasks = (MqComboTask *)malloc((size_t)cap_tasks * sizeof(MqComboTask));
    if (!tasks) return -2;

    int total = 0;
    for (int fi = 0; fi < n_fast; fi++) {
        int fp = fast_list[fi];
        if (fp < 2) continue;
        for (int si = 0; si < n_slow; si++) {
            int sp = slow_list[si];
            if (sp <= fp) continue;
            for (int ai = 0; ai < n_atr; ai++) {
                if (atr_mults[ai] <= 0.1) continue;
                if (total >= cap_tasks) break;
                tasks[total].fast_p = fp;
                tasks[total].slow_p = sp;
                tasks[total].atr_m = atr_mults[ai];
                total++;
            }
        }
    }

    if (total == 0) {
        free(tasks);
        return 0;
    }

    int workers = n_threads > 0 ? n_threads : mq_cpu_threads();
    if (workers > total) workers = total;
    if (workers > 16) workers = 16;
    if (n_candles < 2000 || total < 4) workers = 1; /* avoid thread spawn overhead on tiny workloads */

    if (workers <= 1) {
        MqSweepWorkerArgs single = {
            times, highs, lows, closes, atr, n_candles,
            tasks, 0, total,
            initial_equity, fee_rate, risk_pct, max_leverage, direction, swap_long_rate, swap_short_rate, out_metrics
        };
        eval_combo_slice(&single);
        free(tasks);
        return total;
    }

    MqSweepWorkerArgs args[16];
#ifdef _WIN32
    HANDLE handles[16];
    int spawned = 0;
    for (int w = 0; w < workers; w++) {
        int s = (total * w) / workers;
        int e = (total * (w + 1)) / workers;
        args[w] = (MqSweepWorkerArgs){
            times, highs, lows, closes, atr, n_candles,
            tasks, s, e,
            initial_equity, fee_rate, risk_pct, max_leverage, direction, swap_long_rate, swap_short_rate, out_metrics
        };
        if (w == workers - 1) {
            eval_combo_slice(&args[w]);
        } else {
            handles[spawned] = (HANDLE)_beginthreadex(NULL, 0, win_worker_entry, &args[w], 0, NULL);
            if (handles[spawned]) spawned++;
            else eval_combo_slice(&args[w]);
        }
    }
    if (spawned > 0) {
        WaitForMultipleObjects((DWORD)spawned, handles, TRUE, INFINITE);
        for (int i = 0; i < spawned; i++) CloseHandle(handles[i]);
    }
#else
    pthread_t threads[16];
    int spawned[16] = {0};
    for (int w = 0; w < workers; w++) {
        int s = (total * w) / workers;
        int e = (total * (w + 1)) / workers;
        args[w] = (MqSweepWorkerArgs){
            times, highs, lows, closes, atr, n_candles,
            tasks, s, e,
            initial_equity, fee_rate, risk_pct, max_leverage, direction, swap_long_rate, swap_short_rate, out_metrics
        };
        if (w == workers - 1) {
            eval_combo_slice(&args[w]);
        } else if (pthread_create(&threads[w], NULL, posix_worker_entry, &args[w]) == 0) {
            spawned[w] = 1;
        } else {
            eval_combo_slice(&args[w]);
        }
    }
    for (int w = 0; w < workers - 1; w++) {
        if (spawned[w]) pthread_join(threads[w], NULL);
    }
#endif

    free(tasks);
    return total;
}

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
    double swap_long_rate,
    double swap_short_rate,
    int n_threads,
    double *out_metrics,
    int max_combos
) {
    MqDataset *ds = get_dataset(ds_id);
    if (!ds || !fast_list || !slow_list || !atr_mults || !out_metrics || ds->len < 30) {
        return -1;
    }
    return run_parallel_sweep(
        ds->time_ms,
        ds->high,
        ds->low,
        ds->close,
        ds->atr,
        ds->len,
        fast_list, n_fast,
        slow_list, n_slow,
        atr_mults, n_atr,
        initial_equity, fee_rate, risk_pct, max_leverage, direction,
        swap_long_rate, swap_short_rate,
        n_threads, out_metrics, max_combos
    );
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
    if (!atr) return -2;

    double tr0 = highs[0] - lows[0];
    double floor0 = fabs(closes[0]) * 0.001;
    if (tr0 < floor0) tr0 = floor0;
    if (tr0 <= 0.0) tr0 = 1e-6;
    atr[0] = tr0;
    double tr_sum = tr0;
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

    int written = run_parallel_sweep(
        NULL, highs, lows, closes, atr, n_candles,
        fast_list, n_fast, slow_list, n_slow, atr_mults, n_atr,
        initial_equity, fee_rate, risk_pct, max_leverage, MQ_DIR_LONG,
        0.0, 0.0,
        0, out_metrics, max_combos
    );
    free(atr);
    return written;
}
