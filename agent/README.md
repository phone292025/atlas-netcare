# atlas-agent

The probe agent for Atlas NetCare ([ADR-0013](../docs/decisions/ADR-0013-probe-agent-in-go.md)). It runs on a machine inside the network, checks the devices in its config every 30 seconds, and sends the readings to Atlas's ingest API. One file, no runtime to install.

## What it checks

Each device gets any of three checks, all at once, each with its own timeout. A device that doesn't answer never holds up the others.

| Check | Reachable when | Also measures |
|---|---|---|
| Ping (on unless `ping: false`) | any of 4 echo requests is answered | average round trip as `latency_ms`, `packet_loss_pct` |
| `http: <url>` | the answer is below 500. A login page or a 401 still means the device is there | time to answer, when ping didn't |
| `snmp:` (v2c) | the device returns `sysUpTime` | `client_count` from `clients_oid` |

One reading per device per round. The device is reachable if any of its checks answered.

## Run it

1. In Atlas, as the administrator, open **Agents**, add one and copy the token it shows once into `token.txt`.
2. Set **Watched by** to this agent on each device it should measure.
3. Copy `atlas-agent.example.yaml` to `atlas-agent.yaml` and fill in the address and the devices, by their codes in Atlas.
4. Try it without sending anything:

   ```bash
   atlas-agent -config atlas-agent.yaml -check
   ```

   It prints each device's reading and what each check found.
5. Run it: `atlas-agent -config atlas-agent.yaml`. Run it as a service so it starts with the machine: a systemd unit on Linux, or `sc create` or NSSM on Windows.

Atlas refuses an agent on another machine that connects over plain HTTP. With an internal name, Atlas's certificate comes from its own authority: copy the root out of the stack with `docker compose cp app:/data/caddy/pki/authorities/local/root.crt atlas-root.crt` and set `ca_file` to it. The installation guide's "HTTPS for remote agents" has the rest.

## When Atlas is unreachable

Readings wait in a SQLite file (`buffer`, by default `atlas-agent.db`) and go to Atlas oldest first when it answers again, up to 200 a batch. Each reading's sequence number comes from a counter in the same file, so a restart never reuses one, and Atlas stores a batch sent twice once.

- Atlas down, busy or rate-limiting: the agent waits 2 seconds, doubling up to 5 minutes, with jitter.
- Atlas refuses the token or the address (401, 403): the agent keeps the readings, logs why, and tries again every 5 minutes, until somebody fixes the config.
- Batch too large (413): it halves the batch.
- A reading Atlas refuses (422, which refuses the whole batch for it): the agent sends the batch one reading at a time, drops the one Atlas refuses with the reason in its log, and goes back to full batches. Without that, one bad reading would hold up everything behind it for ever.
- Every 30 seconds with nothing to send, it sends an empty batch as a heartbeat, so Atlas knows the agent is alive.

The buffer holds at most a million readings, a week of 50 devices, and drops the oldest beyond that. Atlas refuses readings more than 7 days old anyway.

## Ping permissions

Raw ICMP needs administrator rights on Windows and root or `CAP_NET_RAW` on Linux: set `privileged_ping: true` and run it that way, or on Linux give the binary the capability with `sudo setcap cap_net_raw=+ep atlas-agent`. Without it, Linux uses unprivileged ping sockets, which the `net.ipv4.ping_group_range` sysctl must allow. Where ping isn't possible, an HTTP or SNMP check still tells Atlas whether the device is up.

## Build and test

Go 1.27.

```bash
cd agent
go test ./...
go build -o atlas-agent ./cmd/atlas-agent
```

Release builds for Windows, Linux x64 and Raspberry Pi (Linux ARM 32 and 64-bit) come from CI's **Agent** job, as artifacts of each run. Each build has no C dependencies, so `GOOS` and `GOARCH` alone cross-compile it:

```bash
GOOS=linux GOARCH=arm64 CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.version=0.1.0" -o atlas-agent ./cmd/atlas-agent
```

The dependencies are pinned in `go.mod`, with checksums in `go.sum`:

| Package | For |
|---|---|
| `github.com/gosnmp/gosnmp` | SNMP v2c, the library Telegraf uses (named in ADR-0013) |
| `github.com/prometheus-community/pro-bing` | ICMP ping on Windows and Linux |
| `modernc.org/sqlite` | the buffer, in pure Go, so cross-compiling needs no C compiler |
| `gopkg.in/yaml.v3` | the config file |
