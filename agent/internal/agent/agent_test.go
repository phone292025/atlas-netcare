package agent

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/phone292025/atlas-netcare/agent/internal/buffer"
	"github.com/phone292025/atlas-netcare/agent/internal/check"
	"github.com/phone292025/atlas-netcare/agent/internal/config"
	"github.com/phone292025/atlas-netcare/agent/internal/sender"
)

// fakeNetwork answers ping for the hosts listed as up, slowly for "slow".
type fakeNetwork struct{ up map[string]bool }

func (f fakeNetwork) Ping(ctx context.Context, host string, _ bool) check.Outcome {
	if host == "slow" {
		<-ctx.Done()
		return check.Outcome{Name: "ping", Err: ctx.Err()}
	}
	loss := 0.0
	if !f.up[host] {
		loss = 100
		return check.Outcome{Name: "ping", LossPct: &loss, Err: errors.New("no reply")}
	}
	return check.Outcome{Name: "ping", Up: true, Latency: 3 * time.Millisecond, LossPct: &loss}
}

func (fakeNetwork) HTTP(context.Context, string) check.Outcome {
	return check.Outcome{Name: "http", Up: true, Latency: 40 * time.Millisecond}
}

func (fakeNetwork) SNMP(context.Context, string, config.SNMP) check.Outcome {
	clients := 17
	return check.Outcome{Name: "snmp", Up: true, Latency: 8 * time.Millisecond, Clients: &clients}
}

// atlas is a fake ingest API that records batches and can be told to fail.
type atlas struct {
	mu      sync.Mutex
	batches []sender.Batch
	status  int
	// refuse answers 422 to any batch holding a reading for this device,
	// as Atlas does when one reading breaks a rule.
	refuse string
	// refuseAll answers 422 to any batch, as a rule about the whole request
	// would: a version string too long, or more readings than Atlas takes.
	refuseAll bool
	// requests counts every request, refused or not.
	requests int
}

func (a *atlas) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mu.Lock()
	defer a.mu.Unlock()

	if r.Header.Get("Authorization") != "Bearer atl_test" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	a.requests++
	if a.status != 0 {
		w.WriteHeader(a.status)
		return
	}
	if a.refuseAll {
		w.WriteHeader(http.StatusUnprocessableEntity)
		_, _ = w.Write([]byte(`{"message":"The agent version field must not be greater than 40 characters."}`))
		return
	}

	body, _ := io.ReadAll(r.Body)
	var b sender.Batch
	_ = json.Unmarshal(body, &b)
	for _, item := range b.Readings {
		if a.refuse != "" && item.Device == a.refuse {
			w.WriteHeader(http.StatusUnprocessableEntity)
			_, _ = w.Write([]byte(`{"message":"The readings.3.latency_ms field must not be greater than 32767."}`))
			return
		}
	}
	a.batches = append(a.batches, b)

	stored := []int64{}
	for _, item := range b.Readings {
		stored = append(stored, item.Sequence)
	}
	_ = json.NewEncoder(w).Encode(sender.Answer{Stored: stored})
}

func (a *atlas) set(status int) { a.mu.Lock(); a.status = status; a.mu.Unlock() }

func (a *atlas) sequences() []int64 {
	a.mu.Lock()
	defer a.mu.Unlock()
	var all []int64
	for _, b := range a.batches {
		for _, item := range b.Readings {
			all = append(all, item.Sequence)
		}
	}
	return all
}

func setUp(t *testing.T, server *httptest.Server, devices ...config.Device) *Agent {
	t.Helper()
	buf, err := buffer.Open(filepath.Join(t.TempDir(), "buffer.db"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { buf.Close() })
	send, err := sender.New(server.URL, "atl_test", "test", "", 2*time.Second)
	if err != nil {
		t.Fatal(err)
	}

	return &Agent{
		Config: &config.Config{Interval: 50 * time.Millisecond, Timeout: 30 * time.Millisecond, BatchSize: 500, Devices: devices},
		Prober: fakeNetwork{up: map[string]bool{"10.0.0.1": true}},
		Buffer: buf,
		Sender: send,
		Log:    slog.New(slog.NewTextHandler(io.Discard, nil)),
	}
}

func TestARoundFoldsEveryCheckIntoOneReadingPerDevice(t *testing.T) {
	server := httptest.NewServer(&atlas{})
	defer server.Close()
	a := setUp(t, server,
		config.Device{Code: "SW", Host: "10.0.0.1", SNMP: &config.SNMP{Community: "public", Port: 161}},
		config.Device{Code: "DOWN", Host: "10.0.0.9"},
		config.Device{Code: "SLOW", Host: "slow", HTTP: "http://slow/"},
	)

	started := time.Now()
	if err := a.Round(context.Background()); err != nil {
		t.Fatal(err)
	}
	if took := time.Since(started); took > 500*time.Millisecond {
		t.Fatalf("a round took %s: a slow device held up the others", took)
	}

	items, _ := a.Buffer.Oldest(10)
	byCode := map[string]buffer.Item{}
	for _, item := range items {
		byCode[item.Device] = item
	}

	sw := byCode["SW"]
	if !sw.Reachable || *sw.LatencyMs != 3 || *sw.PacketLossPct != 0 || *sw.ClientCount != 17 {
		t.Errorf("SW: %+v, want reachable, ping's 3 ms, 0%% loss and SNMP's 17 clients", sw.Reading)
	}
	if down := byCode["DOWN"]; down.Reachable || *down.PacketLossPct != 100 || down.LatencyMs != nil {
		t.Errorf("DOWN: %+v, want unreachable with 100%% loss and no latency", down.Reading)
	}
	if slow := byCode["SLOW"]; !slow.Reachable || *slow.LatencyMs != 40 {
		t.Errorf("SLOW: %+v, want reachable through HTTP at 40 ms though ping timed out", slow.Reading)
	}
}

func TestReadingsTakenWhileAtlasIsDownAreSentInOrderWhenItIsBack(t *testing.T) {
	fake := &atlas{status: http.StatusServiceUnavailable}
	server := httptest.NewServer(fake)
	defer server.Close()
	a := setUp(t, server, config.Device{Code: "SW", Host: "10.0.0.1"})
	a.MinBackoff, a.MaxBackoff = 10*time.Millisecond, 20*time.Millisecond

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = a.Run(ctx); close(done) }()

	time.Sleep(300 * time.Millisecond)
	if n, _ := a.Buffer.Len(); n < 3 {
		t.Fatalf("only %d readings buffered while Atlas was down", n)
	}

	fake.set(0)
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if n, _ := a.Buffer.Len(); n == 0 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	cancel()
	<-done

	got := fake.sequences()
	if len(got) < 3 {
		t.Fatalf("Atlas got %d readings after coming back", len(got))
	}
	for i := 1; i < len(got); i++ {
		if got[i] != got[i-1]+1 {
			t.Fatalf("sequences arrived out of order or with a gap: %v", got)
		}
	}
}

func TestARefusedTokenKeepsTheReadings(t *testing.T) {
	server := httptest.NewServer(&atlas{})
	defer server.Close()
	a := setUp(t, server, config.Device{Code: "SW", Host: "10.0.0.1"})
	a.Sender.(*sender.Sender).Token = "atl_revoked"

	if err := a.Round(context.Background()); err != nil {
		t.Fatal(err)
	}
	_, err := a.SendOnce(context.Background(), 10)

	var refused *sender.Refused
	if !errors.As(err, &refused) || refused.Status != http.StatusUnauthorized {
		t.Fatalf("err = %v, want a 401 refusal", err)
	}
	if n, _ := a.Buffer.Len(); n != 1 {
		t.Fatalf("the buffer holds %d readings after a refusal, want 1", n)
	}
}

func TestAnEmptyBufferSendsAHeartbeat(t *testing.T) {
	fake := &atlas{}
	server := httptest.NewServer(fake)
	defer server.Close()
	a := setUp(t, server, config.Device{Code: "SW", Host: "10.0.0.1"})

	if _, err := a.SendOnce(context.Background(), 10); err != nil {
		t.Fatal(err)
	}
	fake.mu.Lock()
	defer fake.mu.Unlock()
	if len(fake.batches) != 1 || len(fake.batches[0].Readings) != 0 || fake.batches[0].AgentVersion != "test" {
		t.Fatalf("batches = %+v, want one empty heartbeat with the version", fake.batches)
	}
}

func TestAReadingAtlasRefusesIsFoundDroppedAndDoesNotHoldUpTheRest(t *testing.T) {
	fake := &atlas{refuse: "BAD"}
	server := httptest.NewServer(fake)
	defer server.Close()
	a := setUp(t, server, config.Device{Code: "SW", Host: "10.0.0.1"}, config.Device{Code: "BAD", Host: "10.0.0.1"}, config.Device{Code: "AP", Host: "10.0.0.1"})
	a.Config.Interval = time.Hour // one round, then just sending
	a.MinBackoff, a.MaxBackoff = 5*time.Millisecond, 10*time.Millisecond
	if err := a.Round(context.Background()); err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = a.Run(ctx); close(done) }()

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if n, _ := a.Buffer.Len(); n == 0 {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	cancel()
	<-done

	if n, _ := a.Buffer.Len(); n != 0 {
		t.Fatalf("%d readings still buffered: a refused reading held up the rest", n)
	}
	fake.mu.Lock()
	defer fake.mu.Unlock()
	got := map[string]bool{}
	for _, b := range fake.batches {
		for _, item := range b.Readings {
			got[item.Device] = true
		}
	}
	if !got["SW"] || !got["AP"] || got["BAD"] {
		t.Fatalf("Atlas stored %v, want SW and AP and not BAD", got)
	}
}

func TestARefusedHeartbeatWaitsInsteadOfAskingAgainAtOnce(t *testing.T) {
	fake := &atlas{refuseAll: true}
	server := httptest.NewServer(fake)
	defer server.Close()
	a := setUp(t, server, config.Device{Code: "SW", Host: "10.0.0.1"})
	a.Config.Interval = time.Hour
	a.MinBackoff, a.MaxBackoff = time.Millisecond, time.Hour

	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	a.wake = make(chan struct{}, 1)
	a.sendLoop(ctx)

	fake.mu.Lock()
	defer fake.mu.Unlock()
	if fake.requests > 2 {
		t.Fatalf("%d requests in 300 ms: a refused heartbeat was sent again at once", fake.requests)
	}
}

func TestIsolationEndsOnceTheRefusedBatchHasGoneThrough(t *testing.T) {
	fake := &atlas{refuse: "BAD"}
	server := httptest.NewServer(fake)
	defer server.Close()
	a := setUp(t, server, config.Device{Code: "SW", Host: "10.0.0.1"}, config.Device{Code: "BAD", Host: "10.0.0.1"}, config.Device{Code: "AP", Host: "10.0.0.1"})
	ctx := context.Background()

	if err := a.Round(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err := a.SendOnce(ctx, 500); err == nil {
		t.Fatal("the batch with the bad reading was accepted")
	}
	if a.isolateLeft != 3 {
		t.Fatalf("isolateLeft = %d, want the refused batch's 3", a.isolateLeft)
	}
	for i := 0; i < 3; i++ {
		if _, err := a.SendOnce(ctx, 1); err != nil {
			t.Fatalf("send %d: %v", i, err)
		}
	}
	if a.isolateLeft != 0 {
		t.Fatalf("isolateLeft = %d after the batch went through, want 0", a.isolateLeft)
	}
}
