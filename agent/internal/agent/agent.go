// Package agent runs the two loops: one checks every device each interval
// and stores the readings, the other sends what is stored to Atlas.
//
// They meet only in the buffer, so a round of checks never waits for Atlas,
// and readings taken while Atlas is down are sent, oldest first, when it is
// back.
package agent

import (
	"context"
	"errors"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"sync"
	"time"

	"github.com/phone292025/atlas-netcare/agent/internal/buffer"
	"github.com/phone292025/atlas-netcare/agent/internal/check"
	"github.com/phone292025/atlas-netcare/agent/internal/config"
	"github.com/phone292025/atlas-netcare/agent/internal/sender"
)

// MaxBuffered is the most readings kept while Atlas is unreachable: a week
// of 30-second rounds for 50 devices. Atlas refuses older readings anyway.
const MaxBuffered = 1_000_000

// Sending is what the agent needs from the sender. Tests pass their own.
type Sending interface {
	Send(ctx context.Context, items []buffer.Item) (*sender.Answer, error)
}

// Agent holds what both loops share.
type Agent struct {
	Config *config.Config
	Prober check.Prober
	Buffer *buffer.Buffer
	Sender Sending
	Log    *slog.Logger

	// Backoff limits, shortened by tests.
	MinBackoff, MaxBackoff time.Duration

	wake chan struct{}
	// isolateLeft is how many readings are still to go one at a time, after
	// Atlas refused a batch's contents: the refused batch's size. Isolation
	// ends when they have all gone, the bad one dropped, or when it is 0.
	isolateLeft int
}

// Run checks and sends until ctx ends.
func (a *Agent) Run(ctx context.Context) error {
	if a.MinBackoff == 0 {
		a.MinBackoff = 2 * time.Second
	}
	if a.MaxBackoff == 0 {
		a.MaxBackoff = 5 * time.Minute
	}
	a.wake = make(chan struct{}, 1)

	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		a.sendLoop(ctx)
	}()

	ticker := time.NewTicker(a.Config.Interval)
	defer ticker.Stop()

	for {
		if err := a.Round(ctx); err != nil {
			a.Log.Error("could not store the round's readings", "error", err)
		}
		select {
		case <-ctx.Done():
			wg.Wait()
			return nil
		case <-ticker.C:
		}
	}
}

// Round checks every device at once and stores one reading for each.
func (a *Agent) Round(ctx context.Context) error {
	readings := make([]check.Reading, len(a.Config.Devices))
	var wg sync.WaitGroup

	for i, d := range a.Config.Devices {
		wg.Add(1)
		go func() {
			defer wg.Done()
			reading, outcomes := check.Device(ctx, a.Prober, d, a.Config.Timeout, a.Config.Privileged)
			readings[i] = reading
			for _, o := range outcomes {
				if o.Err != nil {
					a.Log.Debug("check failed", "device", d.Code, "check", o.Name, "error", o.Err)
				}
			}
		}()
	}
	wg.Wait()

	if ctx.Err() != nil {
		return nil
	}
	if err := a.Buffer.Add(readings); err != nil {
		return err
	}
	if dropped, err := a.Buffer.Trim(MaxBuffered); err == nil && dropped > 0 {
		a.Log.Warn("buffer full: dropped the oldest readings", "dropped", dropped)
	}

	select {
	case a.wake <- struct{}{}:
	default:
	}

	return nil
}

// sendLoop sends batches while there is anything to send, and a heartbeat
// each interval when there isn't. After a failure it waits longer each time,
// up to MaxBackoff, with jitter so a campus of agents doesn't return at once.
func (a *Agent) sendLoop(ctx context.Context) {
	backoff := a.MinBackoff
	batch := a.Config.BatchSize
	heartbeat := time.NewTimer(a.Config.Interval)
	defer heartbeat.Stop()

	for {
		sent, err := a.SendOnce(ctx, batch)

		var retry *sender.Retry
		var refused *sender.Refused
		wait := time.Duration(0)

		var invalid *sender.Invalid

		switch {
		case err == nil && sent > 0:
			backoff = a.MinBackoff
			if a.isolateLeft == 0 {
				batch = a.Config.BatchSize
			}
			continue
		case errors.As(err, &invalid):
			// SendOnce dropped the reading when the batch was one; any
			// larger and the bad one is somewhere in it.
			batch = 1
			a.Log.Warn("Atlas refused a reading in the batch; sending one at a time to find it", "message", invalid.Message)
			continue
		case err == nil:
			backoff = a.MinBackoff
		case errors.Is(err, sender.ErrTooLarge):
			batch = max(1, batch/2)
			a.Log.Warn("Atlas said the batch was too large; sending fewer", "batch_size", batch)
			continue
		case errors.As(err, &retry):
			wait = max(backoff, retry.After)
			a.Log.Warn("Atlas is unreachable; readings stay in the buffer", "error", retry.Err, "retry_in", wait.Round(time.Second))
			backoff = min(backoff*2, a.MaxBackoff)
		case errors.As(err, &refused):
			// Sending again won't help until somebody fixes the token or
			// the address, so wait the longest and keep the readings.
			wait = a.MaxBackoff
			a.Log.Error("Atlas refused the agent; fix the config, readings stay in the buffer", "status", refused.Status, "message", refused.Message)
		default:
			wait = backoff
			a.Log.Error("could not send", "error", err)
		}

		if wait > 0 {
			wait += time.Duration(rand.Int64N(int64(wait/5) + 1))
			select {
			case <-ctx.Done():
				return
			case <-time.After(wait):
				continue
			}
		}

		select {
		case <-ctx.Done():
			return
		case <-a.wake:
		case <-heartbeat.C:
		}
		if !heartbeat.Stop() {
			select {
			case <-heartbeat.C:
			default:
			}
		}
		heartbeat.Reset(a.Config.Interval)
	}
}

// SendOnce sends up to n of the oldest readings, or a heartbeat when there
// are none, and drops whatever Atlas answered for. It returns how many
// readings went.
func (a *Agent) SendOnce(ctx context.Context, n int) (int, error) {
	items, err := a.Buffer.Oldest(n)
	if err != nil {
		return 0, err
	}

	answer, err := a.Sender.Send(ctx, items)

	var invalid *sender.Invalid
	switch {
	case errors.As(err, &invalid) && len(items) == 0:
		// A heartbeat refused: nothing in the buffer is at fault, so it is
		// the agent itself, a version string too long, say. Wait like any
		// refusal instead of asking again at once.
		return 0, &sender.Refused{Status: http.StatusUnprocessableEntity, Message: invalid.Message}
	case errors.As(err, &invalid) && len(items) == 1:
		// Found it. It would be refused for ever, and everything behind it
		// would wait for ever, so it goes, with the reason in the log.
		a.Log.Error("dropped a reading Atlas refuses", "sequence", items[0].Sequence, "device", items[0].Device, "message", invalid.Message)
		a.isolateLeft = max(0, a.isolateLeft-1)
		return 1, a.Buffer.Drop([]int64{items[0].Sequence})
	case errors.As(err, &invalid):
		a.isolateLeft = len(items)
		return 0, err
	case err != nil:
		return 0, err
	}

	for _, r := range answer.Rejected {
		a.Log.Warn("Atlas rejected a reading", "sequence", r.Sequence, "reason", r.Reason)
	}
	if answer.ClockOffsetMs > 30_000 || answer.ClockOffsetMs < -30_000 {
		a.Log.Warn("this machine's clock is out from Atlas's", "offset_ms", answer.ClockOffsetMs)
	}

	// Stored, duplicate and rejected alike: Atlas has answered for every
	// reading in the batch, and none would be stored if sent again.
	sequences := make([]int64, len(items))
	for i, item := range items {
		sequences[i] = item.Sequence
	}
	a.isolateLeft = max(0, a.isolateLeft-len(items))

	return len(items), a.Buffer.Drop(sequences)
}
