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
    snmp: {user: atlas, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1, clients_oid: .1.3.6.1.4.1.9.9.1}
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
	if s := c.Devices[0].SNMP; !s.V3() || s.Auth != "SHA256" || s.Priv != "AES" || s.Port != 161 {
		t.Fatalf("SNMP defaults not filled in: %+v", s)
	}
	if w := c.Warnings(); len(w) != 0 {
		t.Fatalf("SNMPv3 should need no warning: %v", w)
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

func TestSNMPHasNoDefaultCommunity(t *testing.T) {
	body := strings.Replace(good, "{user: atlas, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1, ", "{", 1)
	_, err := Load(write(t, body))
	if err == nil || !strings.Contains(err.Error(), "no default community") {
		t.Fatalf("SNMP with neither a user nor a community loaded: %v", err)
	}
}

func TestSNMPv2cWorksWithACommunityAndIsWarnedAbout(t *testing.T) {
	body := strings.Replace(good, "user: atlas, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1", "community: lab-ro", 1)
	c, err := Load(write(t, body))
	if err != nil {
		t.Fatal(err)
	}
	if c.Devices[0].SNMP.V3() || c.Devices[0].SNMP.Community != "lab-ro" {
		t.Fatalf("not v2c: %+v", c.Devices[0].SNMP)
	}
	if w := c.Warnings(); len(w) != 1 || !strings.Contains(w[0], "clear text") {
		t.Fatalf("v2c should be warned about: %v", w)
	}
}

func TestABadSNMPv3SetupIsReportedWhole(t *testing.T) {
	for name, snmp := range map[string]struct{ yaml, want string }{
		"both":      {"{user: atlas, community: public, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1}", "not both"},
		"short":     {"{user: atlas, auth_passphrase: short, priv_passphrase: tiny}", "auth_passphrase must be at least 8"},
		"no priv":   {"{user: atlas, auth_passphrase: auth-pass-1}", "priv_passphrase must be at least 8"},
		"bad auth":  {"{user: atlas, auth: MD5, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1}", "auth must be one of"},
		"bad priv":  {"{user: atlas, priv: DES, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1}", "priv must be one of"},
		"lowercase": {"{user: atlas, auth: sha512, priv: aes256, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1}", ""},
	} {
		t.Run(name, func(t *testing.T) {
			body := strings.Replace(good, "{user: atlas, auth_passphrase: auth-pass-1, priv_passphrase: priv-pass-1, clients_oid: .1.3.6.1.4.1.9.9.1}", snmp.yaml, 1)
			_, err := Load(write(t, body))
			if snmp.want == "" {
				if err != nil {
					t.Fatal(err)
				}
				return
			}
			if err == nil || !strings.Contains(err.Error(), snmp.want) {
				t.Fatalf("want %q, got %v", snmp.want, err)
			}
		})
	}
}
