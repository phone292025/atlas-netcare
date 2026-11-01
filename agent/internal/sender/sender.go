// Package sender posts batches to Atlas's ingest API and says what to do
// with the buffer afterwards.
package sender

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/phone292025/atlas-netcare/agent/internal/buffer"
)

// Batch is the request body (ADR-0017).
type Batch struct {
	SentAt       time.Time     `json:"sent_at"`
	AgentVersion string        `json:"agent_version"`
	Readings     []buffer.Item `json:"readings"`
}

// Answer is Atlas's reply to a batch.
type Answer struct {
	Stored        []int64    `json:"stored"`
	Duplicate     []int64    `json:"duplicate"`
	Rejected      []Rejected `json:"rejected"`
	ClockOffsetMs int64      `json:"clock_offset_ms"`
}

// Rejected is a reading Atlas will never store, and why.
type Rejected struct {
	Sequence int64  `json:"sequence"`
	Reason   string `json:"reason"`
}

// ErrTooLarge means the batch was over Atlas's size limit: send fewer.
var ErrTooLarge = errors.New("the batch was too large for Atlas")

// Refused is an answer that sending again won't change, such as a revoked
// token, or a 422 for a batch that is broken as a whole (a version string
// too long). The agent keeps its readings and waits for a person to fix it.
// A single bad reading is not a 422: Atlas rejects it alone, in the 200
// answer, and the agent drops it with the rest.
type Refused struct {
	Status  int
	Message string
}

func (e *Refused) Error() string {
	return fmt.Sprintf("Atlas refused the batch (%d): %s", e.Status, e.Message)
}

// Retry is an answer worth trying again later: Atlas is down, busy or
// limiting this agent. After is how long Atlas asked for, if it did.
type Retry struct {
	Err   error
	After time.Duration
}

func (e *Retry) Error() string { return "Atlas is unreachable for now: " + e.Err.Error() }

// Sender talks to one Atlas.
type Sender struct {
	URL     string
	Token   string
	Version string
	Client  *http.Client
}

// New makes a Sender that also trusts the certificate in caFile, if given.
func New(atlasURL, token, version, caFile string, timeout time.Duration) (*Sender, error) {
	transport := http.DefaultTransport.(*http.Transport).Clone()

	if caFile != "" {
		pem, err := os.ReadFile(caFile)
		if err != nil {
			return nil, fmt.Errorf("reading ca_file: %w", err)
		}
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, fmt.Errorf("ca_file %s holds no PEM certificate", caFile)
		}
		transport.TLSClientConfig = &tls.Config{RootCAs: pool, MinVersion: tls.VersionTLS12}
	}

	return &Sender{
		URL:     strings.TrimRight(atlasURL, "/") + "/api/v1/readings",
		Token:   token,
		Version: version,
		Client:  &http.Client{Timeout: timeout, Transport: transport},
	}, nil
}

// Send posts one batch. An empty one is a heartbeat.
func (s *Sender) Send(ctx context.Context, items []buffer.Item) (*Answer, error) {
	if items == nil {
		items = []buffer.Item{}
	}
	body, err := json.Marshal(Batch{SentAt: time.Now().UTC(), AgentVersion: s.Version, Readings: items})
	if err != nil {
		return nil, err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, s.URL, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+s.Token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "atlas-agent/"+s.Version)

	resp, err := s.Client.Do(req)
	if err != nil {
		return nil, &Retry{Err: err}
	}
	defer resp.Body.Close()
	reply, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))

	switch {
	case resp.StatusCode == http.StatusOK:
		var a Answer
		if err := json.Unmarshal(reply, &a); err != nil {
			return nil, &Retry{Err: fmt.Errorf("unreadable answer: %w", err)}
		}
		return &a, nil
	case resp.StatusCode == http.StatusRequestEntityTooLarge:
		return nil, ErrTooLarge
	case resp.StatusCode == http.StatusTooManyRequests || resp.StatusCode >= 500:
		return nil, &Retry{Err: fmt.Errorf("Atlas answered %d", resp.StatusCode), After: retryAfter(resp.Header.Get("Retry-After"))}
	default:
		return nil, &Refused{Status: resp.StatusCode, Message: message(reply)}
	}
}

func retryAfter(header string) time.Duration {
	if seconds, err := strconv.Atoi(header); err == nil && seconds > 0 {
		return time.Duration(seconds) * time.Second
	}
	return 0
}

func message(body []byte) string {
	var m struct {
		Message string `json:"message"`
	}
	if json.Unmarshal(body, &m) == nil && m.Message != "" {
		return m.Message
	}
	if len(body) > 200 {
		body = body[:200]
	}
	return strings.TrimSpace(string(body))
}
