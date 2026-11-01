// Package config reads the agent's YAML config file and checks it before
// anything runs, so a typo stops the agent at start with a sentence instead
// of failing quietly at three in the morning.
package config

import (
	"errors"
	"fmt"
	"net"
	"net/url"
	"os"
	"strings"
	"time"

	"gopkg.in/yaml.v3"
)

// Config is the whole file. See atlas-agent.example.yaml for every field.
type Config struct {
	// AtlasURL is where Atlas answers, https:// unless Atlas runs on this
	// machine. Atlas refuses plain HTTP from anywhere else.
	AtlasURL string `yaml:"atlas_url"`
	// Token is the agent's token. TokenFile, or ATLAS_AGENT_TOKEN, keeps it
	// out of the config file.
	Token     string `yaml:"token"`
	TokenFile string `yaml:"token_file"`
	// CAFile is a PEM certificate to trust as well as the system's, such as
	// the root of the Caddy authority inside Atlas's container.
	CAFile string `yaml:"ca_file"`
	// Interval is the time between rounds of checks.
	Interval time.Duration `yaml:"interval"`
	// Timeout is how long one check may take before it counts as failed.
	Timeout time.Duration `yaml:"timeout"`
	// Buffer is the SQLite file readings wait in until Atlas has them.
	Buffer string `yaml:"buffer"`
	// BatchSize is the most readings sent in one request. Atlas takes 500.
	BatchSize int `yaml:"batch_size"`
	// Privileged sends ping as raw ICMP, which needs administrator rights
	// on Windows and root or CAP_NET_RAW on Linux. Unprivileged ping uses
	// UDP ICMP sockets, which Linux allows through ping_group_range.
	Privileged bool `yaml:"privileged_ping"`

	Devices []Device `yaml:"devices"`
}

// Device is one device as Atlas knows it, by its code, and how to check it.
type Device struct {
	Code string `yaml:"code"`
	Host string `yaml:"host"`
	// Ping sends a few echo requests to Host. On unless set to false.
	Ping *bool `yaml:"ping"`
	// HTTP fetches a URL and counts any answer below 500 as up.
	HTTP string `yaml:"http"`
	// SNMP asks Host for sysUpTime with SNMP v2c, and for ClientsOID if set.
	SNMP *SNMP `yaml:"snmp"`
}

// SNMP is a v2c community and the optional OID that counts clients.
type SNMP struct {
	Community  string `yaml:"community"`
	Port       uint16 `yaml:"port"`
	ClientsOID string `yaml:"clients_oid"`
}

// PingEnabled says whether this device is pinged.
func (d Device) PingEnabled() bool { return d.Ping == nil || *d.Ping }

// Load reads and checks a config file, filling in the defaults.
func Load(path string) (*Config, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("reading the config: %w", err)
	}

	var c Config
	decoder := yaml.NewDecoder(strings.NewReader(string(raw)))
	decoder.KnownFields(true)
	if err := decoder.Decode(&c); err != nil {
		return nil, fmt.Errorf("reading %s: %w", path, err)
	}

	if c.Interval == 0 {
		c.Interval = 30 * time.Second
	}
	if c.Timeout == 0 {
		c.Timeout = 5 * time.Second
	}
	if c.Buffer == "" {
		c.Buffer = "atlas-agent.db"
	}
	if c.BatchSize == 0 {
		c.BatchSize = 200
	}
	for i := range c.Devices {
		if s := c.Devices[i].SNMP; s != nil {
			if s.Community == "" {
				s.Community = "public"
			}
			if s.Port == 0 {
				s.Port = 161
			}
		}
	}

	if c.Token == "" && c.TokenFile != "" {
		token, err := os.ReadFile(c.TokenFile)
		if err != nil {
			return nil, fmt.Errorf("reading token_file: %w", err)
		}
		c.Token = strings.TrimSpace(string(token))
	}
	if env := os.Getenv("ATLAS_AGENT_TOKEN"); env != "" {
		c.Token = env
	}

	return &c, c.check()
}

func (c *Config) check() error {
	var problems []string

	u, err := url.Parse(c.AtlasURL)
	switch {
	case c.AtlasURL == "" || err != nil || u.Host == "":
		problems = append(problems, "atlas_url must be Atlas's address, like https://atlas.lab.internal")
	case u.Scheme == "http" && !isLoopback(u.Hostname()):
		problems = append(problems, "atlas_url must be https:// unless Atlas runs on this machine: the token travels in every request, and Atlas refuses plain HTTP from other machines")
	case u.Scheme != "http" && u.Scheme != "https":
		problems = append(problems, "atlas_url must start with https://")
	}

	if !strings.HasPrefix(c.Token, "atl_") {
		problems = append(problems, "no token: set token, token_file or ATLAS_AGENT_TOKEN to the token Atlas showed when the agent was added")
	}
	if c.Interval < time.Second {
		problems = append(problems, "interval must be at least 1s")
	}
	if c.Timeout >= c.Interval {
		problems = append(problems, "timeout must be shorter than interval")
	}
	if c.BatchSize < 1 || c.BatchSize > 500 {
		problems = append(problems, "batch_size must be between 1 and 500, Atlas's limit")
	}
	if len(c.Devices) == 0 {
		problems = append(problems, "devices is empty: list at least one device to check")
	}

	seen := map[string]bool{}
	for i, d := range c.Devices {
		where := fmt.Sprintf("devices[%d]", i)
		if d.Code == "" {
			problems = append(problems, where+": code is required, the device's code in Atlas")
		} else if seen[d.Code] {
			problems = append(problems, where+": "+d.Code+" is listed twice")
		}
		seen[d.Code] = true
		if (d.PingEnabled() || d.SNMP != nil) && d.Host == "" {
			problems = append(problems, where+": host is required for ping and SNMP")
		}
		if !d.PingEnabled() && d.HTTP == "" && d.SNMP == nil {
			problems = append(problems, where+": no checks: turn on ping, or set http or snmp")
		}
		if d.HTTP != "" {
			if h, err := url.Parse(d.HTTP); err != nil || h.Host == "" || (h.Scheme != "http" && h.Scheme != "https") {
				problems = append(problems, where+": http must be a full http:// or https:// address")
			}
		}
	}

	if len(problems) > 0 {
		return errors.New("the config has problems:\n  - " + strings.Join(problems, "\n  - "))
	}

	return nil
}

func isLoopback(host string) bool {
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}
