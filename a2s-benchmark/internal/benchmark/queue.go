package benchmark

import (
	"context"
	"time"
)

// Bound limits the number of concurrent in-flight requests. Channels are the
// idiomatic Go semaphore: Acquire sends into the channel and blocks when
// full, Release drains a slot.
type Bound struct {
	slots chan struct{}
}

func NewBound(n int) *Bound {
	if n < 1 {
		n = 1
	}
	return &Bound{slots: make(chan struct{}, n)}
}

// Acquire blocks until a slot is free, or the context is cancelled. Returning
// ctx.Err on cancel is how the runner learns a Ctrl+C came in while we were
// waiting for a slot rather than for a UDP reply.
func (b *Bound) Acquire(ctx context.Context) error {
	select {
	case b.slots <- struct{}{}:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func (b *Bound) Release() {
	<-b.slots
}

// RateLimiter is an optional global cap on how many new requests are started
// per second. Not a token bucket — a simple ticker at 1/N second intervals.
// Under high concurrency this is exact enough; the benchmark's own jitter
// dwarfs anything a leaky bucket would add.
//
// N=0 disables the limiter entirely. The nil-safety on Wait makes callers not
// have to check.
type RateLimiter struct {
	interval time.Duration
	next     chan struct{}
	stop     chan struct{}
}

func NewRateLimiter(perSecond int) *RateLimiter {
	if perSecond <= 0 {
		return nil
	}
	rl := &RateLimiter{
		interval: time.Second / time.Duration(perSecond),
		next:     make(chan struct{}, 1),
		stop:     make(chan struct{}),
	}
	go rl.run()
	return rl
}

func (rl *RateLimiter) Wait(ctx context.Context) error {
	if rl == nil {
		return nil
	}
	select {
	case <-rl.next:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func (rl *RateLimiter) Stop() {
	if rl == nil {
		return
	}
	close(rl.stop)
}

func (rl *RateLimiter) run() {
	t := time.NewTicker(rl.interval)
	defer t.Stop()
	for {
		select {
		case <-t.C:
			// Non-blocking send: if nobody is waiting, drop the tick. A
			// benchmark that started idle would otherwise burst-fire N
			// requests the moment the first worker was ready.
			select {
			case rl.next <- struct{}{}:
			default:
			}
		case <-rl.stop:
			return
		}
	}
}
