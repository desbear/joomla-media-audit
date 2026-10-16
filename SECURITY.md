# Security Policy

Media Audit moves files, downloads images from other sites, runs an image encoder, and rewrites
content. Please report security problems privately so they can be fixed before they are published.

**Do not open a public issue for a vulnerability.** Use GitHub's private vulnerability reporting
instead: on this repository, open the **Security** tab and choose **Report a vulnerability**.

Please include the version, your Joomla and PHP versions, and steps to reproduce. You will get a
response within a week.

## Supported versions

Only the latest release receives security fixes.

## Things to know before you install

- **Saving content can make the site fetch images.** When **Hosts to copy from** is filled in,
  anyone who can save content (authors included) makes the site download the images of those hosts
  that the content refers to, up to 20 MB each, within the time set in the options. With `*` that
  is any public website. Private and local network addresses are refused, unless they belong to a
  host that you named. Name the hosts you need; avoid `*`.
- **Saving content can make the site convert images** when **Format to convert to** is set. Each
  save is limited in time, and images larger than the pixel limit of the options are left alone.
- **The encoder program** is run without a shell, with absolute paths, and only when its file name is
  `avifenc`, `cwebp` or `magick`. Whoever may change the options of the component chooses it:
  Super Users, unless you give that permission to others.
- **Nginx does not read `.htaccess`.** Deny `/images/_mediaaudit_quarantine/` in the server
  configuration, or quarantined images can still be downloaded by whoever knows their address.

## Audit history

[SECURITY-AUDIT.md](SECURITY-AUDIT.md) records what has been reviewed and what has not.
