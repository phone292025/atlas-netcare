# A real SNMP v2c agent for the SNMP check's integration test: net-snmp's
# snmpd from Alpine's own packages, read-only community "public", and one
# extra integer OID standing in for an access point's client count.
#
#   docker build -t atlas-snmpd agent/internal/check/testdata/snmpd
#   docker run -d --rm -p 127.0.0.1:1161:161/udp --name atlas-snmpd atlas-snmpd
#   ATLAS_SNMP_TEST_ADDR=127.0.0.1:1161 go test ./internal/check/ -run SNMP
FROM alpine:3.22
RUN apk add --no-cache net-snmp
COPY snmpd.conf /etc/snmp/snmpd.conf
EXPOSE 161/udp
CMD ["snmpd", "-f", "-Lo", "-C", "-c", "/etc/snmp/snmpd.conf", "udp:161"]
