---
version: 1
slug: "templates-main-php"
primary_target: "templates/main.php"
related_targets: []
---

# Assinafy workspace

Mode: Operate. Authenticated Nextcloud users select a PDF, review the exact Assinafy estimate, send it for signature, and manage the resulting operational records. The interface must remain inside the familiar Nextcloud shell, support narrow mobile layouts, and keep OAuth tokens and webhook secrets out of browser state.

## Direction contract

THESIS: One document moves through a visible, gated path from Nextcloud file to reviewed quote to signature request; it refuses a generic dashboard of equal cards.

OWN-WORLD: Inherit Nextcloud controls, type, spacing, theming, and navigation. Use Assinafy green only for the active workflow and successful states; use standard semantic colors for warnings and errors.

STORY: Connect once, choose a PDF, define recipients and verification methods, review price and requirements, then send. Documents, contracts, and webhooks remain reachable as focused work areas.

FIRST VIEWPORT: A compact header states connection health and the current Assinafy workspace. Below it, a horizontal task navigation leads with Send; the main area pairs a file browser with a linear recipient-and-quote workspace. The primary action remains at the end of the current step.

FORM: Platform-inherited Nextcloud application surface, first structural choice; seed key `platform-inherited:nextcloud-operate`.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

Memorable moment: the estimate replaces the editable workflow with a clear review state, and any changed quote returns the user to that gate instead of sending silently.

Unresolved decisions: none that block the initial implementation.
