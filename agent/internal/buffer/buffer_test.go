package buffer

import (
	"path/filepath"
	"testing"
	"time"

	"github.com/phone292025/atlas-netcare/agent/internal/check"
)

func reading(code string) check.Reading {
	return check.Reading{Device: code, MeasuredAt: time.Now().UTC(), Reachable: true}
}

func TestReadingsSurviveARestartAndSequencesAreNeverReused(t *testing.T) {
	path := filepath.Join(t.TempDir(), "buffer.db")

	b, err := Open(path)
	if err != nil {
		t.Fatal(err)
	}
	if err := b.Add([]check.Reading{reading("A"), reading("B")}); err != nil {
		t.Fatal(err)
	}
	items, _ := b.Oldest(10)
	if err := b.Drop([]int64{items[0].Sequence, items[1].Sequence}); err != nil {
		t.Fatal(err)
	}
	if err := b.Add([]check.Reading{reading("C")}); err != nil {
		t.Fatal(err)
	}
	b.Close()

	b, err = Open(path)
	if err != nil {
		t.Fatal(err)
	}
	defer b.Close()
	if err := b.Add([]check.Reading{reading("D")}); err != nil {
		t.Fatal(err)
	}

	items, err = b.Oldest(10)
	if err != nil {
		t.Fatal(err)
	}
	if len(items) != 2 || items[0].Device != "C" || items[1].Device != "D" {
		t.Fatalf("after a restart the buffer holds %+v", items)
	}
	if items[0].Sequence != 3 || items[1].Sequence != 4 {
		t.Fatalf("sequences %d and %d, want 3 and 4: a restart must not reuse numbers", items[0].Sequence, items[1].Sequence)
	}
}

func TestOldestComesFirstAndTrimDropsTheOldest(t *testing.T) {
	b, err := Open(filepath.Join(t.TempDir(), "buffer.db"))
	if err != nil {
		t.Fatal(err)
	}
	defer b.Close()

	for _, code := range []string{"1", "2", "3", "4", "5"} {
		if err := b.Add([]check.Reading{reading(code)}); err != nil {
			t.Fatal(err)
		}
	}
	if dropped, err := b.Trim(3); err != nil || dropped != 2 {
		t.Fatalf("Trim dropped %d (%v), want 2", dropped, err)
	}
	items, _ := b.Oldest(2)
	if items[0].Device != "3" || items[1].Device != "4" {
		t.Fatalf("oldest two are %s and %s, want 3 and 4", items[0].Device, items[1].Device)
	}
	if n, _ := b.Len(); n != 3 {
		t.Fatalf("Len = %d, want 3", n)
	}
}
