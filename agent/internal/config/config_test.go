package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func write(t *testing.T, body string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "atlas-agent.yaml")
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

const good = `
atlas_url: https://atlas.lab.internal
token: atl_0123456789012345678901234567890123456789
devices:
  - code: SW-L6
    host: 10.20.1.2
    snmp: {clients_oid: .1.3.6.1.4.1.9.9.1}
  - code: AP-01
    host: 10.20.1.10
    http: http://10.20.1.10/
`

func TestAGoodFileLoadsWithItsDefaults(t *testing.T) {
	c, err := Load(write(t, good))
	if err != nil {
		t.Fatal(err)
	}
	if c.Interval != 30*time.Second || c.Timeout != 5*time.Second || c.BatchSize != 200 || c.Buffer != "atlas-agent.db" {
		t.Fatalf("defaults not filled in: %+v", c)
	}
	if s := c.Devices[0].SNMP; s.Community != "public" || s.Port != 161 {
		t.Fatalf("SNMP defaults not filled in: %+v", s)
	}
	if !c.Devices[1].PingEnabled() {
		t.Fatal("ping should be on unless turned off")
	}
}

func TestEveryProblemIsReportedAtOnce(t *testing.T) {
	_, err := Load(write(t, `
atlas_url: http://atlas.lab.internal
devices:
  - code: SW-L6
  - code: SW-L6
    host: 10.0.0.1
    ping: false
  - code: A-DEVICE-CODE-MUCH-LONGER-THAN-FORTY-CHARACTERS
    host: 10.0.0.2
`))
	if err == nil {
		t.Fatal("a broken config loaded")
	}
	for _, want := range []string{"must be https://", "no token", "host is required", "listed twice", "no checks", "longer than"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("the error does not mention %q:\n%s", want, err)
		}
	}
}

func TestPlainHTTPIsAllowedToAtlasOnThisMachine(t *testing.T) {
	body := strings.Replace(good, "https://atlas.lab.internal", "http://127.0.0.1:8000", 1)
	if _, err := Load(write(t, body)); err != nil {
		t.Fatal(err)
	}
}

func TestAMisspeltKeyIsAnError(t *testing.T) {
	_, err := Load(write(t, good+"intervall: 10s\n"))
	if err == nil || !strings.Contains(err.Error(), "intervall") {
		t.Fatalf("a misspelt key was ignored: %v", err)
	}
}

func TestTheTokenCanComeFromTheEnvironment(t *testing.T) {
	body := strings.Replace(good, "token: atl_0123456789012345678901234567890123456789\n", "", 1)
	t.Setenv("ATLAS_AGENT_TOKEN", "atl_fromtheenvironment000000000000000000000")
	c, err := Load(write(t, body))
	if err != nil {
		t.Fatal(err)
	}
	if c.Token != "atl_fromtheenvironment000000000000000000000" {
		t.Fatalf("token = %q", c.Token)
	}
}
