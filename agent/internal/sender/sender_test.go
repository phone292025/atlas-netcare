package sender

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func answering(status int, header map[string]string, body string) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		for k, v := range header {
			w.Header().Set(k, v)
		}
		w.WriteHeader(status)
		_, _ = w.Write([]byte(body))
	}))
}

func TestEachAnswerMeansWhatTheAgentShouldDo(t *testing.T) {
	cases := map[string]struct {
		status int
		header map[string]string
		check  func(error) bool
	}{
		"too large":    {413, nil, func(err error) bool { return errors.Is(err, ErrTooLarge) }},
		"rate limited": {429, map[string]string{"Retry-After": "30"}, func(err error) bool { var r *Retry; return errors.As(err, &r) && r.After == 30*time.Second }},
		"down":         {503, nil, func(err error) bool { var r *Retry; return errors.As(err, &r) }},
		"plain HTTP refused": {403, nil, func(err error) bool {
			var r *Refused
			return errors.As(err, &r) && r.Message == "Agents must connect over HTTPS."
		}},
		"token not accepted": {401, nil, func(err error) bool { var r *Refused; return errors.As(err, &r) && r.Status == 401 }},
	}

	for name, c := range cases {
		server := answering(c.status, c.header, `{"message":"Agents must connect over HTTPS."}`)
		s, _ := New(server.URL, "atl_x", "test", "", time.Second)
		_, err := s.Send(context.Background(), nil)
		server.Close()

		if !c.check(err) {
			t.Errorf("%s: got %v", name, err)
		}
	}
}

func TestACAFileWithNoCertificateIsRefusedAtStart(t *testing.T) {
	path := filepath.Join(t.TempDir(), "root.crt")
	_ = os.WriteFile(path, []byte("not a certificate"), 0o600)

	if _, err := New("https://atlas.lab.internal", "atl_x", "test", path, time.Second); err == nil {
		t.Fatal("a ca_file with no certificate was accepted")
	}
}
