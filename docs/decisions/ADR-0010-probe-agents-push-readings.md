# ADR-0010: Watch a real network through probe agents that push readings

**Status:** Accepted
**Date:** 2026-10-22
**Deciders:** Phone Myat Ko

## Context

Atlas 1.0 was built for a capstone, and its telemetry is simulated. ADR-0002 put a swappable driver behind `TelemetrySource`, and the scheduler pulls one reading per access point each minute from whichever driver is bound. The real driver, `SnmpTelemetrySource`, is a stub that throws.

The pull model can't measure a real network in practice. The driver runs inside Atlas's own PHP process, so it can only reach devices that the machine running Atlas can reach. A campus network, or a home network, sits behind firewalls and NAT that a server is not part of. The ping driver tried in September worked only because the PC running Atlas was on the same home network. It would stop working the moment Atlas ran anywhere else.

Measuring inside the scheduler also ties each collection round to the slowest device. A device that takes its full timeout to answer delays every reading after it.

With the capstone over, the goal for 2.0 is to watch a real network. See [../roadmap.md](../roadmap.md).

## Decision

Readings are measured by **probe agents** and **pushed** to Atlas.

- An agent is a small program that runs inside the network it watches. It pings, makes HTTP checks and, where a device supports it, queries SNMP. It sends readings to Atlas in batches over HTTPS.
- Each agent has its own token, and Atlas knows which devices each agent is responsible for.
- Each reading carries a per-agent sequence number. Atlas stores readings under a unique key, so a batch sent twice, after a timeout or a reconnect, is stored once.
- Each reading stores the time the agent measured it and the time Atlas received it. An agent whose clock disagrees with Atlas by more than 30 seconds is flagged.
- Agents send a heartbeat. When an agent goes silent, its devices read Unknown, not Offline, and the silence itself becomes an alert. This is the rule the Network Monitor already follows when a feed stops.
- An agent keeps readings in a local buffer while it can't reach Atlas, and sends them when it can.
- **The simulator becomes a virtual agent** inside Atlas. It produces its readings through the same ingest code as a real agent, so the demo and the real system share one path.

The agent itself is a separate program in its own folder, written in Go. See [ADR-0013](ADR-0013-probe-agent-in-go.md).

ADR-0002 stays in force until Phase 1 replaces the pull driver. Its status changes to `Superseded by ADR-0010` in the same change.

## Consequences

**Easier**
- Atlas can watch any network an agent can be installed in, including one the server can't reach.
- A slow or dead device can't slow the web app or the scheduler, because measuring happens elsewhere.
- One ingest path for real and simulated readings means one set of tests covers both.
- More than one agent can watch one site, and one Atlas can watch several sites.

**Harder**
- There is a second program to build, release and install.
- The ingest API is a way into Atlas from outside, so it needs authentication, rate limits and validation of every field.
- Readings can arrive late and out of order, so anything summarised by hour has to tolerate a reading for an hour that has already been summarised.

## Alternatives considered

**Keep pulling, from a worker inside the network.** This is the same thing with the direction reversed: Atlas would need to open connections into the network, which firewalls and NAT usually block.

**Use an existing collector**, such as Telegraf or Prometheus exporters, and read their output. They are good tools, but Atlas would then depend on their data model and storage for its core feature. They stay a good option to integrate with later, through the same ingest API.

**SNMP traps sent straight to Atlas.** Devices push events, but traps only report what a device chooses to report, they use UDP with no delivery guarantee, and consumer equipment rarely supports them. An agent can still receive traps later and forward them.
