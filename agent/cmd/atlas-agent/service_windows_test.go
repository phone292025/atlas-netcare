//go:build windows

package main

import (
	"context"
	"errors"
	"testing"
	"time"

	"golang.org/x/sys/windows/svc"
)

// The handler the Service Control Manager talks to, driven by hand: what the
// SCM would see on start, on Stop, and when the agent dies by itself.

func TestTheServiceReportsRunningAndStopsTheAgentOnStop(t *testing.T) {
	stopped := make(chan struct{})
	s := &service{run: func(ctx context.Context) error { <-ctx.Done(); close(stopped); return nil }}
	requests := make(chan svc.ChangeRequest)
	status := make(chan svc.Status, 10)
	result := make(chan uint32)

	go func() { _, code := s.Execute(nil, requests, status); result <- code }()

	if got := (<-status).State; got != svc.StartPending {
		t.Fatalf("first status %v, want StartPending", got)
	}
	running := <-status
	if running.State != svc.Running || running.Accepts&svc.AcceptStop == 0 || running.Accepts&svc.AcceptShutdown == 0 {
		t.Fatalf("second status %+v, want Running and accepting Stop and Shutdown", running)
	}

	requests <- svc.ChangeRequest{Cmd: svc.Stop}

	select {
	case code := <-result:
		if code != 0 {
			t.Fatalf("exit code %d after Stop, want 0", code)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("the service did not stop")
	}
	select {
	case <-stopped:
	default:
		t.Fatal("Stop returned before the agent stopped")
	}
}

func TestAnAgentThatDiesExitsNonZeroSoWindowsRestartsIt(t *testing.T) {
	s := &service{run: func(context.Context) error { return errors.New("buffer is corrupt") }}
	status := make(chan svc.Status, 10)

	_, code := s.Execute(nil, make(chan svc.ChangeRequest), status)

	if code == 0 {
		t.Fatal("an agent that died reported success, so the failure actions would not restart it")
	}
}
