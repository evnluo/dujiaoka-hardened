# Emergency application patch: the inherited PHP/Laravel runtime is still legacy.
FROM ghcr.io/apocalypsor/dujiaoka@sha256:c70109d1c18fd4b936241dce2d3cc84bbd25570fb3a285ce8c9743b22ef692a4 AS runtime
USER root
WORKDIR /dujiaoka
COPY --chown=application app/ ./app/
COPY --chown=application public/ ./public/
COPY --chown=application resources/ ./resources/
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/security.ini /etc/php7/conf.d/zz-security.ini
ENV INSTALL=false APP_ENV=production APP_DEBUG=false
RUN rm -f .env .env.backup bootstrap/cache/config.php bootstrap/cache/routes*.php \
    && composer dump-autoload --no-scripts --optimize \
    && nginx -t
ARG VCS_REF
LABEL org.opencontainers.image.source="https://github.com/evnluo/dujiaoka-hardened" \
      org.opencontainers.image.revision=$VCS_REF \
      org.opencontainers.image.description="Oknice Dujiaoka payment-verification patch; legacy runtime, not a full security audit"

FROM runtime AS security-test
COPY tests/ ./tests/
ENTRYPOINT ["php", "tests/security.php"]

FROM runtime AS final
