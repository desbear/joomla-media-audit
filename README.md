# Media Audit for Joomla

Find out which images of your Joomla site are used, which are not, which are missing and which are
there twice, and clean up without breaking anything. Media Audit reads every place where Joomla
content can point to an image, compares that with the files below `images/`, and lets you move what
nothing uses to a quarantine that you can restore from.

It can also copy the images that your content loads from other sites to your own site, and convert
JPEG and PNG images to AVIF or WebP, rewriting the content to use the new files.

> **Status: beta (0.15.0).** Developed and tested against copies of three Joomla sites with a few
> thousand articles each: Joomla 5.4, Joomla 5.3 with FLEXIcontent, EasyBlog and Falang, and a site
> with FLEXIcontent and Falang on Joomla 4.4 and later 6.1. Read [What is not tested](#what-is-not-tested)
> before you rely on it.

[繁體中文說明](#繁體中文)

## Features

- **Five kinds of images.** *In use*; *unpublished content only* (only unpublished, archived or
  trashed content refers to them); *orphaned* (nothing refers to them); *broken* (content refers to a
  file that is not there); *duplicated* (the same bytes under different names).
- **Reads where images actually are.** Article text and intro/full images, custom fields, modules,
  categories, menu items, tags, contacts, banners, template styles and template CSS. Optional
  plugins add FLEXIcontent (fields and their thumbnails), EasyBlog (posts, covers and their sizes)
  and Falang translations. Other extensions can add their own sources through an event.
- **A quarantine, not a delete button.** Files are moved to `images/_mediaaudit_quarantine/`, closed to
  visitors, and can be restored. A file can only be moved when a fresh scan shows that nothing refers
  to it. Deleting for good is a separate permission, only for files that have been in quarantine for
  a set number of days (30 by default), after a confirmation page, and each file is checked again at
  that moment.
- **External images.** See which images your content loads from other sites, whether they still
  answer, copy them to `images/localized/<host>/` and rewrite the content to load the copy. Lost
  images can be replaced by a short note. Works on save as well, so images that editors paste from
  elsewhere become local.
- **AVIF and WebP.** Convert the images that content uses, next to the originals, keep the result
  only when it is smaller, and rewrite the content. In batches from the admin page, on save, from the
  command line or as a scheduled task. The originals are never changed.
- **Every change to content can be undone.** What a column held before it was rewritten is kept, and
  `--revert` puts it back, unless someone has edited it since.
- **Dashboard and lists** with charts, filters, CSV export, and the source of every reference with a
  link to edit it.
- **Command line, scheduled task, REST API**, and tools for the
  [MCP Server for Joomla](https://github.com/desbear/joomla-mcp) so an AI assistant can read the
  report.

## Requirements

- Joomla 4.4, 5.x or 6.x
- PHP 8.1 or newer, with cURL for anything that touches other sites
- For AVIF: PHP's GD or Imagick with AVIF support, or the `avifenc` program on the server. WebP works
  with PHP's GD in most installations. The *All images* page shows what your server can write.

## Installation

1. Download `pkg_mediaaudit-x.y.z.zip` from the
   [latest release](https://github.com/desbear/joomla-media-audit/releases/latest) (under **Assets**)
   and install it in **System → Install → Extensions**.
2. Open **Components → Media Audit → Options** and fill in **Site domains** with the domains of your
   live site, one per line. The domain you use in the administrator is known without it, but the
   command line and scheduled tasks are not.
3. Press **Scan now** on the overview page. A site with a few thousand images takes seconds.

Nothing is moved, copied, converted or rewritten until you ask for it, except for two options that
act when content is saved, and both do nothing until you fill in what they need: **Hosts to copy
from** and **Format to convert to**.

## Permissions

| Permission | Lets a group | Default |
|---|---|---|
| Access Administration Interface | see the lists and export CSV | as for other components |
| Quarantine and restore files | move files to the quarantine and back | Super Users only |
| Delete files of the quarantine for good | delete after the waiting time | Super Users only |
| Copy external images to this site | use the copy button | Super Users only |
| Convert images to AVIF or WebP | use the convert buttons | Super Users only |

What happens on save is not tied to these permissions: anyone who can save content makes the site
copy the images of the hosts you listed, and convert images when a format is set. See
[SECURITY.md](SECURITY.md) for what that means.

## Command line

```sh
php cli/joomla.php mediaaudit:scan                      # scan and print the report
php cli/joomla.php mediaaudit:scan --format=json
php cli/joomla.php mediaaudit:check-broken --external   # missing files, and external images that do not answer
php cli/joomla.php mediaaudit:localize --dry-run        # copy external images and rewrite the content
php cli/joomla.php mediaaudit:localize --revert         # put the content back
php cli/joomla.php mediaaudit:remove-lost --host=old.example.com --text="Image no longer available" --dry-run
php cli/joomla.php mediaaudit:convert --dry-run         # convert to the format of the options
php cli/joomla.php mediaaudit:convert --revert
```

The scheduled task **Media Audit: scan** scans, can copy and convert a number of images every run,
and e-mails a summary.

## REST API

The plugin **Web Services - Media Audit** is enabled when the package is installed. Send the API
token of a user in the `X-Joomla-Token` header. What the user may do follows the permissions above.

| Method and route | |
|---|---|
| `GET v1/mediaaudit/summary` | the numbers of the last scan |
| `GET v1/mediaaudit/report?type=orphan` | a list: `orphan`, `semi`, `broken`, `duplicate`, `quarantine`, `localized`, `rewrites` |
| `GET v1/mediaaudit/refs?path=images/a.jpg` | what refers to a file |
| `POST v1/mediaaudit/quarantine` | `{"paths": ["images/a.jpg"]}` |
| `POST v1/mediaaudit/restore` | `{"ids": [12]}` |
| `GET` / `POST v1/mediaaudit/scan` | when the last scan was / scan now |

There is no API for deleting, copying or converting.

## How it decides

- **References** are read from HTML attributes (`src`, `srcset`, `href` to an image, `poster`,
  `style` and the `data-` attributes of lazy loaders), from CSS `url()`, from JSON fields, and from
  `images/…` paths written as text. Absolute URLs of your own domains are references to local files.
- **Paths are compared byte for byte.** `Photo.JPG` and `photo.jpg` are different files on Linux,
  even when your computer thinks otherwise; references that differ only by case are flagged.
- **Duplicates** have the same SHA-1. Thumbnails that FLEXIcontent and EasyBlog make of one image are
  left out, since they are often identical and the component needs every one of them.
- **Images that a component makes** (thumbnails, sizes) count as used as long as their original is.
- **Rewriting** changes only the URL, byte for byte, and leaves everything else of the text as it was.
  Content of the [Markdown editor for Joomla](https://github.com/desbear/joomla-markdown-editor) has
  its Markdown source rewritten as well.

Things it cannot know: images linked from other sites, from e-mails or from social media. Such files
look orphaned. That is why moving to the quarantine is the only clean-up step, and why a file has to
wait there before it can be deleted: if someone misses it, restore it.

## What is not rewritten

Image fields of FLEXIcontent (FLEXIcontent makes its thumbnails from the file name, and has its own
WebP option), EasyBlog posts (the composer keeps the content in three places), Falang translations,
template styles (for conversions) and template CSS files. These go on loading the original, which
therefore stays *in use*.

## What is not tested

- Nginx: the quarantine is closed to visitors with `.htaccess` and `web.config`. On Nginx, deny
  `/images/_mediaaudit_quarantine/` in the server configuration.
- AVIF written by PHP's GD or Imagick (tested with `avifenc` only).
- Sending the summary e-mail of the scheduled task (the text is tested, the sending is not).
- Saving content in the FLEXIcontent and EasyBlog editors themselves (the save events are tested by
  calling them the way these components do).
- On Joomla 6.1: installing, scanning and the administrator pages are tested; saving content, the
  REST API and the MCP tools are not.

## Development

The unit tests run without Joomla: `composer install` and `vendor/bin/phpunit`. `./build.sh` writes the
installable package to `dist/`. The scripts in `scripts/` test against a local Joomla site and refuse
to run when its database is not on the same machine; set `MEDIAAUDIT_SITE_ROOT` and
`MEDIAAUDIT_DOMAIN` for them.

## Security

Please report vulnerabilities privately; see [SECURITY.md](SECURITY.md).
[SECURITY-AUDIT.md](SECURITY-AUDIT.md) records what has been reviewed.

## License

GNU General Public License version 2 or later. See [LICENSE](LICENSE).

## 繁體中文

Media Audit 盤點 Joomla 網站 `images/` 底下的圖片：哪些在用、哪些只被未發佈的內容引用、哪些沒人用、哪些掉圖、哪些重複。
清理只會把檔案移到可以還原的隔離區；永久刪除是另一個權限，而且檔案要在隔離區放滿設定的天數（預設 30 天）、經過確認頁、刪除當下再檢查一次引用。

它也能把內容引用的外部網站圖片複製到本站並改寫內容，以及把 JPEG／PNG 轉成 AVIF 或 WebP 並改寫內容。內容的每一次改寫都可以還原。

**下載**：到 [最新版本頁](https://github.com/desbear/joomla-media-audit/releases/latest)，在 **Assets** 下載 `pkg_mediaaudit-x.y.z.zip`（不要下載「Source code」）。

**安裝後**：

1. 「元件 → Media Audit → 選項」的「本站網域」填正式站的網域，每行一個。後台使用的網域會自動算進去，但命令列與排程看不到。
2. 回到總覽按「立即掃描」。

在你填好「要複製的網域」或「轉成哪一種格式」之前，儲存內容不會觸發任何複製或轉換。

**支援的內容**：文章、自訂欄位、模組、分類、選單、標籤、聯絡人、橫幅、樣板樣式與樣板 CSS；另有 FLEXIcontent、EasyBlog、Falang 的外掛。

**它看不到的**：從其他網站、電子郵件、社群貼文連過來的圖片。這些檔案看起來會像孤兒，所以清理只有「移到隔離區」這一步，而且要等一段時間才能刪除。

**Nginx**：隔離區靠 `.htaccess` 與 `web.config` 擋訪客，Nginx 請在伺服器設定裡擋掉 `/images/_mediaaudit_quarantine/`。

**安全性問題**請私下回報，見 [SECURITY.md](SECURITY.md)。
