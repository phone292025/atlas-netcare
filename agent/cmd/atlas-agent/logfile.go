package main

import (
	"os"
	"sync"
)

// maxLogBytes is when the log file is rotated: the current file becomes
// <name>.1, replacing any older one, and a new file starts. Two files of
// this size at most, so a service left running with -verbose for a year
// never fills its disk.
const maxLogBytes = 10 << 20

// rotatingFile is the -log destination.
type rotatingFile struct {
	mu   sync.Mutex
	path string
	file *os.File
	size int64
	max  int64
}

func openRotating(path string, max int64) (*rotatingFile, error) {
	r := &rotatingFile{path: path, max: max}
	return r, r.open()
}

func (r *rotatingFile) open() error {
	f, err := os.OpenFile(r.path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	info, err := f.Stat()
	if err != nil {
		f.Close()
		return err
	}
	r.file, r.size = f, info.Size()
	return nil
}

func (r *rotatingFile) Write(p []byte) (int, error) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.size+int64(len(p)) > r.max && r.size > 0 {
		r.rotate()
	}

	n, err := r.file.Write(p)
	r.size += int64(n)
	return n, err
}

// rotate moves the file to <name>.1 and starts a new one. If it can't, say
// because another program holds the file open on Windows, it keeps
// appending to the current file: a log that is too big beats no log.
func (r *rotatingFile) rotate() {
	r.file.Close()
	_ = os.Remove(r.path + ".1")
	renameErr := os.Rename(r.path, r.path+".1")

	if err := r.open(); err != nil {
		// The file can't be opened at all now; write to standard error so
		// a service manager's own log still has something.
		r.file, r.size = os.Stderr, 0
		return
	}
	if renameErr != nil {
		// Try again only once the file has grown by another limit.
		r.size = 0
	}
}

func (r *rotatingFile) Close() error {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.file == os.Stderr {
		return nil
	}
	return r.file.Close()
}
