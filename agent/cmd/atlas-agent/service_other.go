//go:build !windows

package main

import "context"

// runAsService: only Windows has a Service Control Manager to answer.
// systemd runs the agent as an ordinary process (packaging/atlas-agent.service).
func runAsService(func(ctx context.Context) error) (bool, error) {
	return false, nil
}
