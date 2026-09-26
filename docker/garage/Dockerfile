FROM dxflrs/garage:v2.4.1 AS binary
FROM alpine:3.21

COPY --from=binary /garage /usr/local/bin/garage
COPY garage.toml /etc/garage.toml
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
