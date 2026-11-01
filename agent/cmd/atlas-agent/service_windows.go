//go:build windows

package main

import (
	"context"

	"golang.org/x/sys/windows/svc"
)

// serviceName is what install-windows-service.ps1 registers.
const serviceName = "AtlasAgent"

// runAsService runs the agent under the Service Control Manager when it
// started the process, and reports whether it did. Without this the SCM
// waits for a start report that never comes, and gives up after 30 seconds.
func runAsService(run func(ctx context.Context) error) (bool, error) {
	isService, err := svc.IsWindowsService()
	if err != nil || !isService {
		return false, err
	}

	return true, svc.Run(serviceName, &service{run: run})
}

// service answers the Service Control Manager: running once the agent has
// started, and stopping it on Stop or a Windows shutdown.
type service struct {
	run func(ctx context.Context) error
}

func (s *service) Execute(_ []string, requests <-chan svc.ChangeRequest, status chan<- svc.Status) (bool, uint32) {
	status <- svc.Status{State: svc.StartPending}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan error, 1)
	go func() { done <- s.run(ctx) }()

	status <- svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}

	for {
		select {
		case request := <-requests:
			switch request.Cmd {
			case svc.Interrogate:
				status <- request.CurrentStatus
			case svc.Stop, svc.Shutdown:
				status <- svc.Status{State: svc.StopPending}
				cancel()
				<-done
				return false, 0
			}
		case err := <-done:
			// The agent stopped by itself: a broken buffer file, say. A
			// non-zero exit code makes the service's failure actions restart
			// it, given the failure flag the installer sets (sc.exe failureflag).
			if err != nil {
				return false, 1
			}
			return false, 0
		}
	}
}
