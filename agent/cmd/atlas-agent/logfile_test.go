package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestTheLogRotatesInsteadOfGrowingForEver(t *testing.T) {
	path := filepath.Join(t.TempDir(), "agent.log")
	r, err := openRotating(path, 100)
	if err != nil {
		t.Fatal(err)
	}

	line := []byte(strings.Repeat("x", 39) + "\n")
	for i := 0; i < 10; i++ {
		if _, err := r.Write(line); err != nil {
			t.Fatal(err)
		}
	}
	r.Close()

	current, _ := os.Stat(path)
	older, err := os.Stat(path + ".1")
	if err != nil {
		t.Fatal("no rotated file")
	}
	if current.Size() > 100 || older.Size() > 100 {
		t.Fatalf("files of %d and %d bytes, want each at most 100", current.Size(), older.Size())
	}
	if _, err := os.Stat(path + ".2"); err == nil {
		t.Fatal("more than one old file kept")
	}
}

func TestALogFileOverTheLimitFromALastRunRotatesOnTheFirstWrite(t *testing.T) {
	path := filepath.Join(t.TempDir(), "agent.log")
	_ = os.WriteFile(path, []byte(strings.Repeat("y", 150)), 0o600)

	r, err := openRotating(path, 100)
	if err != nil {
		t.Fatal(err)
	}
	_, _ = r.Write([]byte("new\n"))
	r.Close()

	if got, _ := os.ReadFile(path); string(got) != "new\n" {
		t.Fatalf("current log = %q, want only the new line", got)
	}
}
