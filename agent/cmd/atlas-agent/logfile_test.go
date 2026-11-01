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

func TestARotationThatCannotRenameKeepsLogging(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "agent.log")
	r, err := openRotating(path, 10)
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()
	// A directory where the old file would go makes the rename fail.
	if err := os.Mkdir(path+".1", 0o700); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(filepath.Join(path+".1", "keep"), []byte("x"), 0o600)

	for i := 0; i < 5; i++ {
		if _, err := r.Write([]byte("0123456789\n")); err != nil {
			t.Fatalf("write %d after a failed rotation: %v", i, err)
		}
	}
	if got, _ := os.ReadFile(path); len(got) == 0 {
		t.Fatal("nothing logged after the rotation failed")
	}
}
