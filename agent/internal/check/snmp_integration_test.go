package check

import (
	"context"
	"net"
	"os"
	"strconv"
	"testing"
	"time"

	"github.com/phone292025/atlas-netcare/agent/internal/config"
)

// The client-count OID testdata/snmpd/snmpd.conf answers 17 on:
// NET-SNMP-EXTEND-MIB::nsExtendResult."clients".
const testClientsOID = ".1.3.6.1.4.1.8072.1.3.2.3.1.4.7.99.108.105.101.110.116.115"

// snmpTarget is the real snmpd from testdata/snmpd, or a skip. CI's Agent
// job starts it and sets ATLAS_SNMP_TEST_ADDR; locally, see the Dockerfile.
func snmpTarget(t *testing.T) (string, uint16) {
	t.Helper()
	addr := os.Getenv("ATLAS_SNMP_TEST_ADDR")
	if addr == "" {
		t.Skip("ATLAS_SNMP_TEST_ADDR is not set: no SNMP agent to ask")
	}
	host, port, err := net.SplitHostPort(addr)
	if err != nil {
		t.Fatal(err)
	}
	p, _ := strconv.Atoi(port)
	return host, uint16(p)
}

func TestSNMPReadsUptimeAndTheClientCountFromARealAgent(t *testing.T) {
	host, port := snmpTarget(t)
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	o := NewNetwork().SNMP(ctx, host, config.SNMP{Community: "public", Port: port, ClientsOID: testClientsOID})

	if !o.Up || o.Err != nil {
		t.Fatalf("a real snmpd read as down: %v", o.Err)
	}
	if o.Clients == nil || *o.Clients != 17 {
		t.Fatalf("client count = %v, want 17", o.Clients)
	}
}

func TestSNMPWithTheWrongCommunityIsDown(t *testing.T) {
	host, port := snmpTarget(t)
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	if o := NewNetwork().SNMP(ctx, host, config.SNMP{Community: "wrong", Port: port}); o.Up {
		t.Fatal("an agent that ignores us read as up")
	}
}

func TestSNMPWithoutTheVendorsOIDLeavesTheClientCountOut(t *testing.T) {
	host, port := snmpTarget(t)
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	o := NewNetwork().SNMP(ctx, host, config.SNMP{Community: "public", Port: port, ClientsOID: ".1.3.6.1.4.1.99999.1.1"})

	if !o.Up {
		t.Fatalf("a device without the vendor OID read as down: %v", o.Err)
	}
	if o.Clients != nil {
		t.Fatalf("client count = %d for an OID the device doesn't have", *o.Clients)
	}
}
