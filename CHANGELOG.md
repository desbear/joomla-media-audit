# Changelog

## 0.15.0

First public release.

- **The site knows its own domain.** The domain that the administrator is opened with, and
  `live_site` of the global configuration, count as domains of the site without being listed in the
  options. Before, a site that writes its images with its full address had them reported as images
  of another site, and the files as orphans, until the option was filled in.
- **A file that may be linked from the site is not moved.** An address of any host that ends in the
  path of a file now counts as a reference when the quarantine is asked to move it.
- **The overview warns about hosts that look like the site**: hosts whose addresses lead to files that
  the site has, at the same path, and that are not listed as domains of the site.
- No more deprecation notices on PHP 8.5 (`curl_close()`, `imagedestroy()`).
- Tested on Joomla 6.1.

### Security hardening

From a second, independent review of the file, network and process handling; see
[SECURITY-AUDIT.md](SECURITY-AUDIT.md).

- Downloads are limited by the bytes written after unpacking, not by the bytes on the wire.
- Only the ports of the web are asked (80, 443, 8080, 8443), except on hosts that you named.
- One time limit for a download and all its redirects; saving gives the encoder only the time that is
  left.
- Restore and permanent delete check the real path as well as the text of the path.
- Converted files and local copies are created exclusively: nothing is overwritten and no link is
  followed. Images that are links are not converted.
- Proxy settings of the environment are not used.

## 0.14.0

Security fixes, after a review of the whole package; see [SECURITY-AUDIT.md](SECURITY-AUDIT.md).

- Rewriting URLs could turn text inside an attribute value into attributes of its own (stored
  cross-site scripting by authors). Attributes are now read the way a browser reads them, and both
  kinds of quotes are encoded.
- Requests to other sites followed redirects without checking them, and could be pointed at private
  addresses. Every redirect is now checked, and the connection goes to the address that was checked.
- A path that is not valid UTF-8 stopped every scan. Content made to be slow to parse took minutes.
- Restoring trusts the disk and not the database; files that take the place of a converted file or a
  copy are not used; CSV cells, host names and messages are made safe.

## 0.13.1

- Duplicates can be selected all at once, or group by group.

## 0.13.0

- Values of custom fields and text fields of FLEXIcontent are rewritten as well. Template styles are
  left out of conversions.

## 0.12.0

- Images can be converted to AVIF or WebP, with the content rewritten to load the new files.

## 0.11.0

- The page of external images shows how far copying has come. The charts of the overview link to the
  lists they count.

## 0.10.0

- The overview shows charts. External images can be copied from the toolbar.

## 0.9.0

- Files of the quarantine can be deleted for good, after a waiting time, with a permission of its own.

## 0.8.0

- Pages for copies of external images and for the changes made to content.

## 0.1.0 to 0.7.0

- Scan, report, quarantine and restore; FLEXIcontent, EasyBlog and Falang; external images; the
  scheduled task, the command line, the REST API and the MCP tools.
