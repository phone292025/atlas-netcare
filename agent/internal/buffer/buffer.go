// Package buffer keeps readings in a SQLite file until Atlas has them.
//
// Every reading gets its sequence number here, when it is stored, from a
// counter in the same file. Atlas stores a reading once per agent, sequence
// and time, so a batch sent again after a lost answer is stored once. The
// counter survives restarts, so a restarted agent never reuses a number.
package buffer

import (
	"database/sql"
	"encoding/json"
	"fmt"

	_ "modernc.org/sqlite" // pure Go, so the agent cross-compiles without cgo

	"github.com/phone292025/atlas-netcare/agent/internal/check"
)

// Item is a reading as it travels: its sequence, then its fields.
type Item struct {
	Sequence int64 `json:"sequence"`
	check.Reading
}

// Buffer is the open file.
type Buffer struct {
	db *sql.DB
}

// Open opens or creates the buffer file.
func Open(path string) (*Buffer, error) {
	db, err := sql.Open("sqlite", "file:"+path+"?_pragma=journal_mode(WAL)&_pragma=busy_timeout(5000)&_pragma=synchronous(NORMAL)")
	if err != nil {
		return nil, err
	}
	// One connection: SQLite has one writer, and the agent is one process.
	db.SetMaxOpenConns(1)

	_, err = db.Exec(`
		CREATE TABLE IF NOT EXISTS readings (sequence INTEGER PRIMARY KEY, body TEXT NOT NULL);
		CREATE TABLE IF NOT EXISTS counter (id INTEGER PRIMARY KEY CHECK (id = 1), last INTEGER NOT NULL);
		INSERT OR IGNORE INTO counter (id, last) VALUES (1, 0);`)
	if err != nil {
		db.Close()
		return nil, fmt.Errorf("preparing %s: %w", path, err)
	}

	return &Buffer{db: db}, nil
}

// Close closes the file.
func (b *Buffer) Close() error { return b.db.Close() }

// Add numbers the readings and stores them, all or none.
func (b *Buffer) Add(readings []check.Reading) error {
	tx, err := b.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback() //nolint:errcheck // a no-op after Commit

	var last int64
	if err := tx.QueryRow(`UPDATE counter SET last = last + ? WHERE id = 1 RETURNING last`, len(readings)).Scan(&last); err != nil {
		return err
	}

	first := last - int64(len(readings)) + 1
	for i, r := range readings {
		body, err := json.Marshal(r)
		if err != nil {
			return err
		}
		if _, err := tx.Exec(`INSERT INTO readings (sequence, body) VALUES (?, ?)`, first+int64(i), string(body)); err != nil {
			return err
		}
	}

	return tx.Commit()
}

// Oldest returns up to n readings, oldest first.
func (b *Buffer) Oldest(n int) ([]Item, error) {
	rows, err := b.db.Query(`SELECT sequence, body FROM readings ORDER BY sequence LIMIT ?`, n)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []Item
	for rows.Next() {
		var item Item
		var body string
		if err := rows.Scan(&item.Sequence, &body); err != nil {
			return nil, err
		}
		if err := json.Unmarshal([]byte(body), &item.Reading); err != nil {
			return nil, err
		}
		items = append(items, item)
	}

	return items, rows.Err()
}

// Drop removes readings Atlas has answered for.
func (b *Buffer) Drop(sequences []int64) error {
	tx, err := b.db.Begin()
	if err != nil {
		return err
	}
	defer tx.Rollback() //nolint:errcheck // a no-op after Commit

	for _, s := range sequences {
		if _, err := tx.Exec(`DELETE FROM readings WHERE sequence = ?`, s); err != nil {
			return err
		}
	}

	return tx.Commit()
}

// Len is how many readings are waiting.
func (b *Buffer) Len() (int, error) {
	var n int
	err := b.db.QueryRow(`SELECT count(*) FROM readings`).Scan(&n)
	return n, err
}

// Trim drops the oldest readings beyond max, so an agent cut off from Atlas
// for weeks doesn't fill its disk. Atlas refuses readings older than 7 days
// anyway. Returns how many went.
func (b *Buffer) Trim(max int) (int64, error) {
	res, err := b.db.Exec(`DELETE FROM readings WHERE sequence NOT IN (SELECT sequence FROM readings ORDER BY sequence DESC LIMIT ?)`, max)
	if err != nil {
		return 0, err
	}
	return res.RowsAffected()
}
