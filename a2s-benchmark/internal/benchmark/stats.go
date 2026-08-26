package benchmark

import (
	"sync/atomic"
	"time"

	"github.com/lobbyhub/a2s-benchmark/internal/metrics"
	"github.com/lobbyhub/a2s-benchmark/internal/snapshot"
)

// Counters is the live tally the progress ticker reads. Everything the
// ticker needs is atomic so the ticker doesn't hold a lock while the runner
// is pushing results in. The latency histogram takes its own lock; that lock
// is only held for the length of an append.
type Counters struct {
	Processed     atomic.Int64
	Responded     atomic.Int64
	Timeout       atomic.Int64
	NetworkError  atomic.Int64
	ProtocolError atomic.Int64
	Malformed     atomic.Int64
	PacketsSent   atomic.Int64
	InFlight      atomic.Int64
	// PlayersOnline is the running sum of players reported by responded
	// servers. It feeds the games.players_online write at the end of a
	// sweep so the tool does not have to re-aggregate over server_states.
	PlayersOnline atomic.Int64
	StartedAt     time.Time
}

func NewCounters() *Counters {
	return &Counters{StartedAt: time.Now()}
}

func (c *Counters) Record(s snapshot.Snapshot, hist *metrics.Histogram) {
	c.Processed.Add(1)
	c.PacketsSent.Add(int64(s.Packets))
	if s.Latency > 0 {
		hist.Add(s.Latency)
	}
	switch s.Outcome {
	case snapshot.OutcomeResponded:
		c.Responded.Add(1)
		if s.Info != nil {
			c.PlayersOnline.Add(int64(s.Info.PlayersOnline))
		}
	case snapshot.OutcomeTimeout:
		c.Timeout.Add(1)
	case snapshot.OutcomeNetworkError:
		c.NetworkError.Add(1)
	case snapshot.OutcomeProtocolError:
		c.ProtocolError.Add(1)
	case snapshot.OutcomeMalformed:
		c.Malformed.Add(1)
	}
}

// Peak keeps the largest int64 it has ever been Update'd with.
//
// CAS loop rather than a mutex: the ticker updates this once a second, and
// no consumer waits on it — a simple non-blocking write is cheaper than a
// mutex acquisition per update.
type Peak struct{ v atomic.Int64 }

func (p *Peak) Update(v int64) {
	for {
		cur := p.v.Load()
		if v <= cur {
			return
		}
		if p.v.CompareAndSwap(cur, v) {
			return
		}
	}
}

func (p *Peak) Load() int64 { return p.v.Load() }
