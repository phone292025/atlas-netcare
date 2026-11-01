package check

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestAnyAnswerBelow500CountsAsUp(t *testing.T) {
	for status, up := range map[int]bool{200: true, 302: true, 401: true, 404: true, 500: false, 503: false} {
		server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			if status == 302 {
				w.Header().Set("Location", "/login")
			}
			w.WriteHeader(status)
		}))

		o := NewNetwork().HTTP(context.Background(), server.URL)
		server.Close()

		if o.Up != up {
			t.Errorf("answer %d: up = %v, want %v (%v)", status, o.Up, up, o.Err)
		}
	}
}

func TestASelfSignedAdminPageIsStillAnAnswer(t *testing.T) {
	server := httptest.NewTLSServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	defer server.Close()

	if o := NewNetwork().HTTP(context.Background(), server.URL); !o.Up {
		t.Fatalf("a device with a self-signed certificate read as down: %v", o.Err)
	}
}

func TestNoAnswerIsDown(t *testing.T) {
	server := httptest.NewServer(http.NotFoundHandler())
	url := server.URL
	server.Close()

	if o := NewNetwork().HTTP(context.Background(), url); o.Up || o.Err == nil {
		t.Fatalf("a closed port read as up")
	}
}
