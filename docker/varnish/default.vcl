vcl 4.1;

# HTTP cache in front of the API. API Platform tags every response with the IRIs it contains
# ("Cache-Tags" header) and sends a BAN request with a regex of IRIs when resources change, so
# public responses can be cached for long without ever serving outdated data.
#
# Requests with an Authorization header or cookies are never cached (Varnish's built-in logic),
# so personal data (/api/me, /api/orders) always comes straight from the app.

import std;

backend default {
    .host = "php";
    .port = "80";
    .connect_timeout = 5s;
    .first_byte_timeout = 60s;
    .between_bytes_timeout = 30s;
    .max_connections = 300;
    .probe = {
        .url = "/api";
        .interval = 10s;
        .timeout = 5s;
        .window = 5;
        .threshold = 3;
    }
}

# Callers that may ban: the private Docker network. On its own that's not enough, because the
# platform's reverse proxy also sits on that network and forwards public requests (see vcl_recv).
acl invalidators {
    "localhost";
    "10.0.0.0"/8;
    "172.16.0.0"/12;
    "192.168.0.0"/16;
}

sub vcl_recv {
    if (req.restarts > 0) {
        set req.hash_always_miss = true;
    }

    if (req.method == "BAN") {
        # The app purges via http://varnish, so the Host is "varnish". Public requests arrive through
        # the reverse proxy, which only routes the site's own domain, so they can't pass this check.
        if (client.ip !~ invalidators || req.http.host !~ "^varnish(:[0-9]+)?$") {
            return (synth(405, "Not allowed"));
        }
        if (req.http.ApiPlatform-Ban-Regex) {
            ban("obj.http.Cache-Tags ~ " + req.http.ApiPlatform-Ban-Regex);
            return (synth(200, "Ban added"));
        }
        return (synth(400, "The ApiPlatform-Ban-Regex HTTP header must be set."));
    }

    # Health check for the platform (Coolify, Docker): answered by Varnish itself.
    if (req.method == "GET" && req.url == "/healthz") {
        return (synth(200, "OK"));
    }

    # The OAuth2 login flow is personal and session-based: never cache it, whatever the headers say.
    if (req.url ~ "^/(authorize|login|logout|token)([/?]|$)") {
        return (pass);
    }
}

sub vcl_hit {
    if (obj.ttl >= 0s) {
        return (deliver);
    }
    # Expired: serve the stale copy for a short while if the app is healthy (and refresh it in the
    # background), or for up to the full grace period while the app is down.
    if (std.healthy(req.backend_hint)) {
        if (obj.ttl + 15s > 0s) {
            return (deliver);
        }
        return (restart);
    }
    if (obj.ttl + obj.grace > 0s) {
        return (deliver);
    }
    return (restart);
}

sub vcl_backend_response {
    # Keep objects for an hour after they expire, so the API keeps answering if the app is down.
    set beresp.grace = 1h;
}

sub vcl_deliver {
    # The tags are only for Varnish; clients don't need the (potentially long) list of IRIs.
    unset resp.http.Cache-Tags;

    # Makes caching visible for debugging: "HIT" or "MISS".
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
}
