# ADR-0013: Write the probe agent in Go

**Status:** Accepted
**Date:** 2026-10-22
**Deciders:** Phone Myat Ko

## Context

[ADR-0010](ADR-0010-probe-agents-push-readings.md) adds a probe agent: a separate program that runs inside a network, measures its devices and pushes readings to Atlas. It has needs the web app doesn't:

- It runs for months without anyone watching it, on whatever machine is inside the network: a Windows PC, a Linux box or a Raspberry Pi.
- It has to be easy to install. Ideally that means copying one file, with no runtime to install first.
- It runs many slow checks at once. A device that doesn't answer must not hold up the others.
- It needs ping, HTTP checks and SNMP, with SNMP from mature code, because campus switches speak it.

Atlas is now a solo project, so the language doesn't need to be one a team already knows. It talks to Atlas only through the ingest API, which is HTTPS and JSON. A later rewrite in another language therefore changes nothing in Atlas.

## Decision

The agent is written in **Go**, in its own folder (`agent/`) in this repository, with its own tests and release builds.

- **Checks:** ping, HTTP and SNMP (v2c first, v3 later) in the first version. Each check has its own timeout, and checks run concurrently.
- **Buffer:** readings that can't be sent wait in a local SQLite file and are sent in order when Atlas is reachable again.
- **Release:** one binary per platform (Windows, Linux x64, Linux ARM for a Raspberry Pi), built in CI.

## Consequences

**Easier**
- Installing an agent means copying one file and a config.
- Go's standard library covers HTTP and concurrency, and the network libraries it needs are proven: `gosnmp` is the SNMP library Telegraf uses.
- Cross-compiling for every target is one build flag.

**Harder**
- A second language in the repository, with its own toolchain, tests and CI job.
- Go is new to the project. Its rules are few, but they take some getting used to.

## Alternatives considered

**Rust.** It produces one binary as well, and its compiler prevents whole classes of crashes. It lost on two points. It's much slower to learn, and its SNMP libraries are younger than Go's and Python's. Rewriting the agent in Rust later stays possible, because the ingest API doesn't depend on the agent's language.

**Python.** It's the fastest to write, and it has the best SNMP libraries. But every machine that runs the agent would need Python installed and kept up to date. Its main advantage was that the rest of the team works in Python, and that no longer applies.

**PHP.** Same language as Atlas. It's weak for long-running concurrent network work, and heavy to install on a Raspberry Pi.
