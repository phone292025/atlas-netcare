// Command atlas-agent measures the devices in its config and sends the
// readings to Atlas NetCare (docs/decisions/ADR-0013-probe-agent-in-go.md).
//
//	atlas-agent -config atlas-agent.yaml          run until stopped
//	atlas-agent -config atlas-agent.yaml -check   check the config and every device once, send nothing
//	atlas-agent -config ... -log agent.log        log to a file, as a Windows service must
//
// Started by the Windows Service Control Manager, it runs as a service and
// stops when the service is stopped (service_windows.go).
package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/phone292025/atlas-netcare/agent/internal/agent"
	"github.com/phone292025/atlas-netcare/agent/internal/buffer"
	"github.com/phone292025/atlas-netcare/agent/internal/check"
	"github.com/phone292025/atlas-netcare/agent/internal/config"
	"github.com/phone292025/atlas-netcare/agent/internal/sender"
)

// version is set by the release build: -ldflags "-X main.version=0.1.0".
var version = "dev"

func main() {
	path := flag.String("config", "atlas-agent.yaml", "the config file")
	once := flag.Bool("check", false, "check the config and every device once, print the readings, send nothing")
	verbose := flag.Bool("verbose", false, "log every failed check")
	showVersion := flag.Bool("version", false, "print the version and stop")
	logFile := flag.String("log", "", "append the log to this file instead of standard error, rotated at 10 MB")
	flag.Parse()

	if *showVersion {
		fmt.Println(version)
		return
	}

	level := slog.LevelInfo
	if *verbose {
		level = slog.LevelDebug
	}
	var logTo io.Writer = os.Stderr
	if *logFile != "" {
		f, err := openRotating(*logFile, maxLogBytes)
		if err != nil {
			fmt.Fprintln(os.Stderr, "opening the log file:", err)
			os.Exit(2)
		}
		defer f.Close()
		logTo = f
	}
	log := slog.New(slog.NewTextHandler(logTo, &slog.HandlerOptions{Level: level}))

	cfg, err := config.Load(*path)
	if err != nil {
		fmt.Fprintln(logTo, err)
		os.Exit(2)
	}

	if *once {
		os.Exit(checkOnce(context.Background(), cfg))
	}

	run := func(ctx context.Context) error { return runAgent(ctx, cfg, log) }

	// Under the Windows Service Control Manager the service decides when to
	// stop; anywhere else, Ctrl+C or SIGTERM do.
	if asService, err := runAsService(run); asService || err != nil {
		if err != nil {
			log.Error("the service stopped with an error", "error", err)
			os.Exit(1)
		}
		return
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	if err := run(ctx); err != nil {
		os.Exit(1)
	}
}

// runAgent checks and sends until ctx ends.
func runAgent(ctx context.Context, cfg *config.Config, log *slog.Logger) error {

	buf, err := buffer.Open(cfg.Buffer)
	if err != nil {
		log.Error("could not open the buffer", "file", cfg.Buffer, "error", err)
		return err
	}
	defer buf.Close()

	send, err := sender.New(cfg.AtlasURL, cfg.Token, version, cfg.CAFile, cfg.Timeout*2)
	if err != nil {
		log.Error("could not set up the connection to Atlas", "error", err)
		return err
	}

	waiting, _ := buf.Len()
	log.Info("atlas-agent started", "version", version, "atlas", cfg.AtlasURL, "devices", len(cfg.Devices), "interval", cfg.Interval, "buffered", waiting)

	a := &agent.Agent{Config: cfg, Prober: check.NewNetwork(), Buffer: buf, Sender: send, Log: log}
	if err := a.Run(ctx); err != nil {
		log.Error("stopped", "error", err)
		return err
	}
	log.Info("atlas-agent stopped")

	return nil
}

// checkOnce runs one round and prints it as JSON, for setting an agent up.
func checkOnce(ctx context.Context, cfg *config.Config) int {
	p := check.NewNetwork()
	failed := 0
	out := json.NewEncoder(os.Stdout)
	out.SetIndent("", "  ")

	for _, d := range cfg.Devices {
		reading, outcomes := check.Device(ctx, p, d, cfg.Timeout, cfg.Privileged)
		report := map[string]any{"reading": reading}
		checks := map[string]string{}
		for _, o := range outcomes {
			if o.Err != nil {
				checks[o.Name] = o.Err.Error()
			} else {
				checks[o.Name] = "ok"
			}
		}
		report["checks"] = checks
		_ = out.Encode(report)
		if !reading.Reachable {
			failed++
		}
	}

	fmt.Fprintf(os.Stderr, "%d of %d devices answered. Nothing was sent to Atlas.\n", len(cfg.Devices)-failed, len(cfg.Devices))
	return 0
}
