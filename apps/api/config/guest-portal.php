<?php

return [
    'magic_link_ttl_minutes' => (int) env('GUEST_PORTAL_MAGIC_LINK_TTL', 10080),
    'session_ttl_minutes' => (int) env('GUEST_PORTAL_SESSION_TTL', 1440),
];
