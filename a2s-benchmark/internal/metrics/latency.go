// Package metrics keeps a growing list of latencies and answers percentile
// questions about it. Not a HDR histogram; the benchmark's cardinality
// (hundreds of thousands of samples per run) is small enough for a plain
// sorted slice to be fast and exact.
package metrics

import (
	"sort"
	"sync"
	"time"
)

// Histogram collects latency samples and produces percentiles on demand.
//
// Concurrency: Add is safe from many goroutines; Snapshot copies the slice
// under the same lock, so a reader can produce percentiles while writers
// keep adding.
type Histogram struct {
	mu      sync.Mutex
	samples []time.Duration
	buckets [6]int64 // <50ms, 50-100, 100-250, 250-500, 500-1000, >1000
}

func New(hint int) *Histogram {
	return &Histogram{samples: make([]time.Duration, 0, hint)}
}

func (h *Histogram) Add(d time.Duration) {
	h.mu.Lock()
	h.samples = append(h.samples, d)
	h.buckets[bucketOf(d)]++
	h.mu.Unlock()
}

// Snapshot returns a copy of the collected samples plus the bucket counts.
// Sorted here rather than in Add so the fast path (adding a sample) stays
// cheap; sorting once at the end is dominant for the benchmark anyway.
func (h *Histogram) Snapshot() ([]time.Duration, [6]int64) {
	h.mu.Lock()
	out := make([]time.Duration, len(h.samples))
	copy(out, h.samples)
	buckets := h.buckets
	h.mu.Unlock()

	sort.Slice(out, func(i, j int) bool { return out[i] < out[j] })
	return out, buckets
}

// Percentiles returns p50, p95, p99 in ms rounded to int. Zero-length input
// returns zeros.
func Percentiles(sorted []time.Duration) (p50, p95, p99 time.Duration) {
	n := len(sorted)
	if n == 0 {
		return 0, 0, 0
	}
	return sorted[pIndex(n, 50)], sorted[pIndex(n, 95)], sorted[pIndex(n, 99)]
}

// Avg returns the arithmetic mean of the samples.
func Avg(sorted []time.Duration) time.Duration {
	if len(sorted) == 0 {
		return 0
	}
	var total time.Duration
	for _, d := range sorted {
		total += d
	}
	return total / time.Duration(len(sorted))
}

func pIndex(n, percentile int) int {
	// Nearest-rank method: rank = ceil(p/100 * n), 1-indexed → return
	// rank-1 clamped into [0, n-1]. Not linear interpolation; the benchmark
	// reports whole-millisecond figures anyway.
	idx := (percentile*n + 99) / 100
	if idx <= 0 {
		return 0
	}
	if idx > n {
		idx = n
	}
	return idx - 1
}

// BucketNames labels the six latency ranges the summary prints.
var BucketNames = [6]string{
	"<50ms",
	"50-100ms",
	"100-250ms",
	"250-500ms",
	"500-1000ms",
	">1000ms",
}

func bucketOf(d time.Duration) int {
	ms := d.Milliseconds()
	switch {
	case ms < 50:
		return 0
	case ms < 100:
		return 1
	case ms < 250:
		return 2
	case ms < 500:
		return 3
	case ms < 1000:
		return 4
	default:
		return 5
	}
}
