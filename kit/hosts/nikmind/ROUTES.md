# Per-route pages for iranian.nikmindclinic.com

Every page of that site is the same single HTML app; the script shows the right part with JS.
`transform.py` makes each route carry only its own content in the raw HTML (what bots and AI readers see):

- sections the route does not show are reduced to an empty skeleton (section tag + every element with an id),
  so the site's script still finds what it looks for and nothing breaks;
- containers the script fills (profile body, FAQ, services, testimonials, about grids, therapist cards) are
  pre-filled with the same HTML the script produces, rendered in headless Chrome (`render.py`);
- therapist cards get a crawlable link; About / Therapists get an H1 (same look as their H2).

`validate.py` renders old and new side by side: no JS errors, same visible sections, same text, same containers.
`apply-routes.php` puts the new pages on the server, skipping any file that changed since the snapshot.

Run again after the site is regenerated from its source (e.g. new city pages): snapshot → transform → validate → apply.
