
### Reconcile with the newer Ask the Data workspace before merging

Main moved the assistant into the shared `/ask-the-data` engine with dashboard
approval and private projects. Preserve that workspace and its statistical/source
features. The admin compatibility page/API still require strict admin access;
the engine only enables uploaded-library retrieval after resolving a strict admin
server-side. Default tool creation excludes uploaded-library sources (including
background project jobs), and uploaded chunk lookup independently rechecks admin
on either citation path. Existing approved-user access to other dataset/project
features is unchanged. The new processing migration uses a distinct timestamp
from main's analysis-source migration. Reconciliation checks: 1,796 tests passed,
33 database-dependent cases skipped; types, prefixed build and migration lint pass.
