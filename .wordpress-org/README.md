# wordpress.org directory assets — OrderRing Lite

These files are **SVN `/assets/` art**, not part of the plugin zip. They are
excluded from the distributable by `bin/build-zip.sh` and `tools/build-release.sh`.
After the slug is approved, commit them into the plugin's SVN `/assets/`
directory (sibling of `/trunk/`), NOT into `/trunk/`.

Palette: Tillpress Shopfront — coral `#ff5a3c` on warm white `#fff9f4`.

## Header art
- `banner-1544x500.png` — hi-DPI plugin header banner
- `banner-772x250.png` — standard plugin header banner
- `icon-256x256.png` — hi-DPI plugin icon
- `icon-128x128.png` — standard plugin icon

## Screenshots (map 1:1 to the `== Screenshots ==` captions in readme.txt)
Captured from a fresh WordPress 7.1 + WooCommerce install with **only**
OrderRing Lite active (no Pro screens: no License tab, no voice, no WhatsApp).
- `screenshot-1.png` — settings screen (Twilio credentials, no license key)
- `screenshot-2.png` — Ready-for-Pickup message template with merge tags
- `screenshot-3.png` — Send SMS from the WooCommerce order screen (consent badge)
- `screenshot-4.png` — Twilio credentials + "Test connection"

Regenerate art with `~/wp-verify/make_art.py`; recapture screenshots with
`~/wp-verify/capture.py` (headless Chrome over CDP against the local verify site).
