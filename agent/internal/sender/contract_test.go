package sender

import (
	"encoding/json"
	"os"
	"testing"
	"time"

	"github.com/phone292025/atlas-netcare/agent/internal/buffer"
	"github.com/phone292025/atlas-netcare/agent/internal/check"
)

// contractFile is the batch both sides test against: this test checks the
// agent encodes exactly it, and Atlas's ReadingIngestTest posts it and
// expects every reading stored. A field renamed on either side fails one.
const contractFile = "../../testdata/batch.json"

func TestTheAgentEncodesTheContractBatch(t *testing.T) {
	latency, loss, clients, lost := 12.4, 0.0, 9, 100.0
	at := func(s string) time.Time { v, _ := time.Parse(time.RFC3339, s); return v }

	batch := Batch{
		SentAt:       at("2026-10-28T10:00:00Z"),
		AgentVersion: "0.1.0",
		Readings: []buffer.Item{
			{Sequence: 1, Reading: check.Reading{Device: "AP-06", MeasuredAt: at("2026-10-28T09:59:30Z"), Reachable: true, LatencyMs: &latency, PacketLossPct: &loss, ClientCount: &clients}},
			{Sequence: 2, Reading: check.Reading{Device: "AP-06", MeasuredAt: at("2026-10-28T09:59:45Z"), Reachable: false, PacketLossPct: &lost}},
		},
	}

	got, err := json.MarshalIndent(batch, "", "  ")
	if err != nil {
		t.Fatal(err)
	}

	if os.Getenv("UPDATE_CONTRACT") != "" {
		if err := os.WriteFile(contractFile, append(got, '\n'), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	want, err := os.ReadFile(contractFile)
	if err != nil {
		t.Fatal(err)
	}
	if string(got)+"\n" != string(want) {
		t.Fatalf("the agent's batch no longer matches %s. If Atlas accepts the new shape, rerun with UPDATE_CONTRACT=1.\ngot:\n%s", contractFile, got)
	}
}
