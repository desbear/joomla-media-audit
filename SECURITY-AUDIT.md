# Security audit

Media Audit moves and deletes files, downloads images from other sites, runs an image encoder, and
rewrites the content of a site. This page records what has been reviewed, what was wrong, and what has
*not* been checked. To report something new, see [SECURITY.md](SECURITY.md); please do it privately.

## What was audited

| | First review | Second review |
|---|---|---|
| Date | 1 October 2026 | 17 October 2026 |
| Version reviewed | 0.13.1 | 0.14.0, with the changes of 0.15.0 |
| Version with the fixes | 0.14.0 | 0.15.0 |
| Scope | The whole package, in three passes: access control and entry points; files, network and processes; output escaping and content rewriting | Files, network and processes again, as an independent pass; the report of that pass in the first review was cut off before it was complete |

**This was an internal review, not a third-party penetration test.** It was carried out with the help
of AI agents. Every finding was reproduced, or its code path read end to end, before it was accepted,
and every fix has a test. Treat the result as a careful first pass, not as a clean bill of health.

Who was assumed to be an attacker:

| Who | Controls |
|---|---|
| An author or editor who is not an administrator | the content they save: its HTML, image file names, image alt text, the addresses of external images |
| A website that images are downloaded from | its answers: headers, redirects, error text, the bytes it sends |
| A visitor who is not logged in | requests |

## Found and fixed

### High

**Stored cross-site scripting through rewriting (fixed in 0.14.0).** To replace the address of an
image, the rewriter looked for `src="…"` anywhere in a tag, and so also inside the value of another
attribute such as `alt`. It decoded the value and encoded only one kind of quote again. An author could
save content that Joomla's text filter accepts as harmless text in an `alt`, and that came out of a
rewrite as an event handler, run for every visitor. Attributes are now read one after the other, the
way a browser reads them, and both kinds of quotes are encoded. Present since copying external images
was added.

### Medium

| Issue | Fixed in |
|---|---|
| Requests to other sites followed redirects without checking where they led, resolved the name twice (once to check, once to connect), and the check of whether an external image still exists had no address check at all. On PHP 8.1 and 8.2, some ways of writing an IPv4 address as IPv6 passed as public. An author could make the server request private addresses. | 0.14.0 |
| A path that is not valid UTF-8 (`%FF` in an image address) made writing the index fail and stopped every scan; after a day, quarantining stops too, because it needs a recent scan. | 0.14.0 |
| Content made to be slow to parse (long runs of backticks in Markdown, tens of thousands of tags, many references) took minutes on save and on every scan. | 0.14.0 |

### Low

| Issue | Fixed in |
|---|---|
| Restoring trusted the paths of the database record; a tampered record could move a file out of `images/`. | 0.14.0 |
| A folder inside `images/` that is a link to elsewhere let files outside `images/` be moved or converted. | 0.14.0 |
| When a converted file or a local copy was moved away and someone uploaded a file of that name, content was made to load it. | 0.14.0 |
| CSS strings that contain `url(`, candidates of a `srcset`, and objects in JSON were changed beyond the address. | 0.14.0 |
| CSV cells starting with a tab or line break escaped the formula guard; host names in the summary e-mail could be sentences; temporary file names were predictable; some messages were not escaped. | 0.14.0 |
| The download limit counted compressed bytes: a small gzip answer could unpack to gigabytes in the temporary folder before the time limit stopped it. | 0.15.0 |
| Any port of a public address could be asked, which can reach services that only a firewall keeps from the world. Now 80, 443, 8080 and 8443, except for hosts an administrator named. | 0.15.0 |
| Each redirect had the full time limit; saving gave the encoder up to two minutes per image whatever the time set for saving. One limit now covers all redirects, and the encoder gets what is left. | 0.15.0 |
| The host that a request names counted as a domain of the site (new in 0.15.0, never released); a visitor whose request runs a scheduled scan could choose it. Only the administrator's host counts now. | 0.15.0 |
| Restore and permanent delete compared the text of paths, not the real path; with a tampered record and a linked folder they could reach outside. | 0.15.0 |
| Files were written after checking that the name was free, and could overwrite what appeared in between, or follow a link. They are now created exclusively and links are refused. | 0.15.0 |
| Proxy settings of the environment would resolve names again, past the address check. Proxies are not used. | 0.15.0 |

## Verified to be right

- Every action that changes something checks the form token and its own permission; nothing that
  moves, deletes, downloads, converts or scans can be triggered with a link.
- Backend users without *Access Administration Interface* for the component see nothing and cannot
  export.
- The REST API answers 401 to every route without a token, and has no route for deleting, copying or
  converting.
- Permanent delete cannot skip the confirmation page, refuses ids that were not on it, and checks every
  condition again for each file at the moment of deleting.
- All input that reaches SQL is quoted, cast to an integer, or checked against a list.
- All output in the administrator is escaped; addresses shown as links are always http or https.
- Downloads are judged by their content: only JPEG, PNG, GIF, WebP and AVIF are stored, never SVG,
  under a name made of letters, digits, `-` and `_`, with the extension of the type that was found.
- The image encoder is run without a shell, with absolute paths, and the pixel limit is checked from
  the header before an image is decoded.
- `unserialize()` is only used on FLEXIcontent values, with no classes allowed.

## Not checked, or accepted

- **Saving content can make the site fetch and convert images**, for any author, when the options ask
  for it. This is how the feature works; see [SECURITY.md](SECURITY.md).
- **Nginx** does not read the `.htaccess` that closes the quarantine; it has to be denied in the
  server configuration.
- **Name resolution has no time limit of its own.** A slow DNS server can hold up the check of external
  images.
- **Someone who can create links in `images/` on the server** could race the checks for links. That
  needs access to the files of the site.
- **Concurrent scans** are not prevented; anyone who may use the component can start several.
- The review did not include a test against a running production server.
