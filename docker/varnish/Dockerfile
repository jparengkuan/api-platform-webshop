# Varnish with the project's VCL baked in, so deploying needs no bind mounts.
FROM varnish:8.0

COPY --link default.vcl /etc/varnish/default.vcl
