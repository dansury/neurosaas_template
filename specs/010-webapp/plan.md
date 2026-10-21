# Plan

Extract the dependency-free Web Push encryption/transport from kp-atlant.
Keep subscriptions and VAPID keys in the existing private, deployment-preserved
admin/account/data directory. Add a small WebApp facade, a settings controller,
a browser client and a root-scoped worker. Reuse the administration gate and
CSRF rather than creating another login. Public endpoints serve only manifest
and static assets; application events call the PHP facade directly.

The service worker caches only the module's public offline screen. Its activation
deletes only caches belonging to this module. Notification URLs are constrained
to the service origin. Keep the installer/runtime mirror synchronized.
