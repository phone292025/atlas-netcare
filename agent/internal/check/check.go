// Package check measures one device: ping, an HTTP request and SNMP, each
// with its own timeout, all at once, so a device that doesn't answer never
// holds up the others.
package check

import (
	"context"
	"crypto/tls"
	"fmt"
	"math"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/gosnmp/gosnmp"
	probing "github.com/prometheus-community/pro-bing"

	"github.com/phone292025/atlas-netcare/agent/internal/config"
)

// Reading is what one round found on one device, in Atlas's field names.
// A nil field was not measured and is left out of the batch.
type Reading struct {
	Device        string    `json:"device"`
	MeasuredAt    time.Time `json:"measured_at"`
	Reachable     bool      `json:"reachable"`
	LatencyMs     *float64  `json:"latency_ms,omitempty"`
	PacketLossPct *float64  `json:"packet_loss_pct,omitempty"`
	ClientCount   *int      `json:"client_count,omitempty"`
}

// Outcome is what one check found.
type Outcome struct {
	Name      string
	Up        bool
	Latency   time.Duration
	LossPct   *float64
	Clients   *int
	Err       error
	Attempted bool
}

// Prober runs one kind of check. The real ones talk to the network; tests
// pass their own.
type Prober interface {
	Ping(ctx context.Context, host string, privileged bool) Outcome
	HTTP(ctx context.Context, url string) Outcome
	SNMP(ctx context.Context, host string, s config.SNMP) Outcome
}

// Device runs every check the device has at once and folds them into one
// reading. It is reachable if any check got an answer. Latency is ping's
// average when ping answered, otherwise the fastest answer. Packet loss comes
// from ping, and the client count from SNMP.
func Device(ctx context.Context, p Prober, d config.Device, timeout time.Duration, privileged bool) (Reading, []Outcome) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	var (
		mu       sync.Mutex
		wg       sync.WaitGroup
		outcomes []Outcome
	)
	run := func(f func() Outcome) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			o := f()
			o.Attempted = true
			mu.Lock()
			outcomes = append(outcomes, o)
			mu.Unlock()
		}()
	}

	if d.PingEnabled() {
		run(func() Outcome { return p.Ping(ctx, d.Host, privileged) })
	}
	if d.HTTP != "" {
		run(func() Outcome { return p.HTTP(ctx, d.HTTP) })
	}
	if d.SNMP != nil {
		run(func() Outcome { return p.SNMP(ctx, d.Host, *d.SNMP) })
	}
	wg.Wait()

	return fold(d.Code, outcomes), outcomes
}

func fold(code string, outcomes []Outcome) Reading {
	r := Reading{Device: code, MeasuredAt: time.Now().UTC()}
	var fastest time.Duration

	for _, o := range outcomes {
		if o.LossPct != nil {
			r.PacketLossPct = o.LossPct
		}
		if o.Clients != nil {
			r.ClientCount = o.Clients
		}
		if !o.Up {
			continue
		}
		r.Reachable = true
		if o.Name == "ping" {
			r.LatencyMs = millis(o.Latency)
		} else if fastest == 0 || o.Latency < fastest {
			fastest = o.Latency
		}
	}
	if r.Reachable && r.LatencyMs == nil && fastest > 0 {
		r.LatencyMs = millis(fastest)
	}

	return r
}

// millis rounds to a tenth of a millisecond, and caps at Atlas's column.
func millis(d time.Duration) *float64 {
	ms := math.Min(math.Round(float64(d.Microseconds())/100)/10, 32767)
	return &ms
}

// Network is the Prober that really sends packets.
type Network struct {
	Client *http.Client
}

// NewNetwork makes a Prober whose HTTP checks don't follow redirects (a
// redirect is an answer) and accept any certificate: the check asks whether
// the device answers, and a self-signed admin page is still an answer.
func NewNetwork() *Network {
	return &Network{Client: &http.Client{
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Transport: &http.Transport{
			TLSClientConfig:   &tls.Config{InsecureSkipVerify: true}, //nolint:gosec // see above
			DisableKeepAlives: true,
		},
	}}
}

// Ping sends four echo requests a quarter of a second apart.
func (n *Network) Ping(ctx context.Context, host string, privileged bool) Outcome {
	o := Outcome{Name: "ping"}
	pinger, err := probing.NewPinger(host)
	if err != nil {
		o.Err = err
		return o
	}
	pinger.Count = 4
	pinger.Interval = 250 * time.Millisecond
	pinger.SetPrivileged(privileged)
	// pro-bing marks echoes as network control (CS6) by default, and Windows
	// refuses that setting to anyone but an administrator, failing the ping.
	pinger.SetTrafficClass(0)
	if deadline, ok := ctx.Deadline(); ok {
		// Finish just inside the check's deadline, so a device that never
		// answers still ends with statistics: 100% loss, not no figure.
		pinger.Timeout = max(time.Until(deadline)-100*time.Millisecond, 100*time.Millisecond)
	}
	err = pinger.RunWithContext(ctx)
	stats := pinger.Statistics()
	if err != nil && stats.PacketsSent == 0 {
		o.Err = err
		return o
	}

	loss := math.Round(stats.PacketLoss*100) / 100
	o.LossPct = &loss
	o.Up = stats.PacketsRecv > 0
	o.Latency = stats.AvgRtt
	if !o.Up {
		o.Err = fmt.Errorf("no reply from %s", host)
	}

	return o
}

// HTTP counts any answer below 500 as up: a login page or a 401 still
// means the device is there.
func (n *Network) HTTP(ctx context.Context, url string) Outcome {
	o := Outcome{Name: "http"}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		o.Err = err
		return o
	}
	req.Header.Set("User-Agent", "atlas-agent")

	started := time.Now()
	resp, err := n.Client.Do(req)
	if err != nil {
		o.Err = err
		return o
	}
	resp.Body.Close()
	o.Latency = time.Since(started)
	o.Up = resp.StatusCode < 500
	if !o.Up {
		o.Err = fmt.Errorf("%s answered %d", url, resp.StatusCode)
	}

	return o
}

const sysUpTime = ".1.3.6.1.2.1.1.3.0"

// SNMP asks for sysUpTime, and the client count when the device has an OID
// for it.
func (n *Network) SNMP(ctx context.Context, host string, s config.SNMP) Outcome {
	o := Outcome{Name: "snmp"}
	timeout := 2 * time.Second
	if deadline, ok := ctx.Deadline(); ok {
		timeout = time.Until(deadline)
	}
	g := &gosnmp.GoSNMP{
		Target: host, Port: s.Port, Community: s.Community, Version: gosnmp.Version2c,
		Timeout: timeout, Retries: 0, Context: ctx,
	}
	if err := g.Connect(); err != nil {
		o.Err = err
		return o
	}
	defer g.Conn.Close()

	oids := []string{sysUpTime}
	if s.ClientsOID != "" {
		oids = append(oids, s.ClientsOID)
	}

	started := time.Now()
	packet, err := g.Get(oids)
	if err != nil {
		o.Err = err
		return o
	}
	o.Latency = time.Since(started)
	o.Up = true

	for _, v := range packet.Variables {
		if v.Type == gosnmp.NoSuchObject || v.Type == gosnmp.NoSuchInstance {
			continue
		}
		if s.ClientsOID != "" && strings.TrimPrefix(v.Name, ".") == strings.TrimPrefix(s.ClientsOID, ".") {
			if count := gosnmp.ToBigInt(v.Value); count.IsInt64() && count.Int64() >= 0 {
				clients := int(min(count.Int64(), 32767))
				o.Clients = &clients
			}
		}
	}

	return o
}
