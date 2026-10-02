# syntax=docker/dockerfile:1.7
ARG HUB_BASE_IMAGE
ARG POLICY_LIBRARY_CARRIER
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac
FROM ${POLICY_LIBRARY_CARRIER} AS module
FROM ${COMPOSER_IMAGE} AS composer
FROM ${HUB_BASE_IMAGE}
ARG HUB_BASE_IMAGE
ARG POLICY_LIBRARY_CARRIER
ARG POLICY_LIBRARY_REVISION
ARG PREPARATION_RUN_ID

# The private carrier supplies code only. Hub vendor/assets/runtime and all
# Python packages come from this newly accepted Hub base and fresh installation.
USER root
COPY --from=composer /usr/bin/composer /usr/local/bin/policy-library-composer
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/composer.json /opt/mbfd-policy-library/composer.json
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/src/ /opt/mbfd-policy-library/src/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/config/ /opt/mbfd-policy-library/config/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/database/ /opt/mbfd-policy-library/database/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/routes/ /opt/mbfd-policy-library/routes/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/resources/views/ /opt/mbfd-policy-library/resources/views/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/public/ /opt/mbfd-policy-library/public/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/tools/import_sources.py /opt/mbfd-policy-library/tools/requirements.txt /opt/mbfd-policy-library/tools/
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/scripts/install-in-hub.php /opt/mbfd-policy-library/scripts/install-in-hub.php
COPY --from=module --chown=sail:sail /opt/mbfd-policy-library/source.json /opt/mbfd-policy-library/source.json
COPY --from=module /opt/mbfd-policy-library/scripts/install-in-hub.php /usr/local/lib/mbfd/install-policy-library.php
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3-venv \
    && python3 -m venv /opt/policy-library-python \
    && /opt/policy-library-python/bin/python -m pip install --no-cache-dir -r /opt/mbfd-policy-library/tools/requirements.txt \
    && php /usr/local/lib/mbfd/install-policy-library.php /var/www/html /opt/mbfd-policy-library "$POLICY_LIBRARY_REVISION" "$HUB_BASE_IMAGE" \
    && /usr/local/bin/policy-library-composer dump-autoload --no-dev --no-scripts --optimize --working-dir=/var/www/html \
    && cat /opt/mbfd-policy-library/config/supervisord-policy-library.conf >> /etc/supervisor/conf.d/supervisord.conf \
    && rm /usr/local/bin/policy-library-composer \
    && rm -rf /var/lib/apt/lists/*
ENV POLICY_LIBRARY_PYTHON_BINARY=/opt/policy-library-python/bin/python
LABEL org.opencontainers.image.source="https://github.com/pdarleyjr/mbfd-policy-library" \
      mbfd.policy-library.revision="${POLICY_LIBRARY_REVISION}" \
      mbfd.policy-library.base-image="${HUB_BASE_IMAGE}" \
      mbfd.policy-library.carrier="${POLICY_LIBRARY_CARRIER}" \
      mbfd.hub.preparation-run-id="${PREPARATION_RUN_ID}"
USER sail
