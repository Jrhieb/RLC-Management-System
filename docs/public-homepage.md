# RLCP public homepage

Implemented for the existing named `home` route (`GET /`) on 30 October 2026.
The route still renders `welcome`; there is no second application or new public
page route. The supplied preview guides the composition and section order.
Photographs remain deliberately identified placeholders until approved public
assets are supplied.

The homepage has a welcome bar, navigation over the hero, About tabs and photo
collage, four values, three program cards, care section, community gallery,
charcoal contact callout, announcements empty state, and footer. Layout uses a
1200px maximum container, 96px desktop section spacing (80px on tablets), 56px
mobile spacing, 24–28px card gaps, and body line height 1.65. The care and gallery
form one continuous visual area, with no duplicated padding between them.
Boundaries are straight. Gold surfaces use dark text; brown replaces low-contrast
gold text on light backgrounds.

| File or directory | Change and purpose |
| --- | --- |
| `resources/views/welcome.blade.php` | Replaces Laravel starter content with the public homepage. Only static public copy; no model queries or contact form. |
| `resources/views/components/public/brand.blade.php` | Header/footer identity using the supplied emblem. |
| `resources/views/components/public/icon.blade.php` | Small decorative interface icons, separate from the emblem artwork. |
| `resources/css/public-home.css` | Homepage styling, responsive layouts, focus states, and reduced-motion rules. Selectors use `.rlcp-public` or `.rlcp-public-document`. |
| `resources/js/public-home.js` | Scoped progressive enhancement for navigation and About tabs; no fetches or polling. |
| `vite.config.js` | Adds two public entry points alongside existing staff entry points. |
| `public/images/branding/rlc-logo.png` | Unmodified copy of the supplied PNG. |
| `public/images/homepage/placeholders/` | Seven small, local SVG placeholders serving eleven image positions. |
| `public/build/` | Production Vite output and asset/font manifests. |
| `scripts/build-public-assets.mjs` | Runs the existing Vite Plus build with an empty temporary `envDir`, so project `.env` files are not loaded. |
| `tests/Feature/PublicHomepageTest.php` | Public render, assets, links, images, and guest protection using the existing isolated harness. No migrations or database queries. |
| `tests/browser/public-homepage.test.mjs` | Existing Chromium/CDP approach; serves only isolated test markup and public assets through request interception. |
| `docs/qa/public-homepage/` | Desktop, tablet, and mobile captures plus machine-readable browser results. |

The logo source is `C:\rlcsystem\app\RLC logo.png`, confirmed as a real PNG by its
`89 50 4E 47 0D 0A 1A 0A` signature. It is 300 × 300 pixels, 32-bit RGBA, with
transparent corner pixels. Visual inspection confirmed the RLCP circular emblem
with the cross, red cloth, flame/dove, and Bible. The source remains in place.
Source and public copy have the identical SHA-256:

```text
DFDA7702EE50C92C01792A3A93F82601AD802E4BE02DD5A10D3A3D962AC26FDC
```

The image is contained at its original aspect ratio, with a white CSS backing
behind the transparent artwork to keep its black lettering readable over the
dark header/footer. There is no recoloring, raster editing, conversion, or crop.
It is also used as the homepage favicon; shared staff favicons are unchanged.

Home/About/Programs/Activities/Contact target real section IDs. Staff Login uses
`route('login')`; Apply as Caseworker uses `route('register')`. Footer program
links target the corresponding public cards. Each program card's “Our approach”
is a native expandable disclosure with draft detail, usable without JavaScript.
Gallery arrows target related public content. The contact section provides an
honest notice that public contact details are not yet available and links to the
public announcements section. It has no submission backend or invented address,
phone number, or email.

The mobile navigation is a nonmodal disclosure: native button keyboard controls,
Escape returning focus, outside-click/focus dismissal, resize reconciliation, and
accurate `aria-expanded`/`aria-controls`. Selecting a section moves focus to its
destination. Without JavaScript, navigation stays visible. About links become
ARIA tabs only after initialization, with Arrow Left/Right, Home, End, Space,
Enter, roving tabindex, labelled panels, and fragment support. Without JavaScript,
all three sections remain visible under ordinary links. Reveal effects never
set opacity to zero; reduced motion disables animation, hover movement, and
smooth scrolling.

No individual approved public photographs were found in `public` or `resources`.
No beneficiary photographs, private files, mockup crops, stock downloads, or
generated substitute photos were used. Replace these eleven slots with
owner-approved photographs:

| Position | Current local placeholder | Suggested source size / displayed crop |
| --- | --- | --- |
| Hero | `hero.svg` | 1920 × 1080; text on left, subject on right; dark overlay retained |
| About rectangle | `learning.svg` | 1200 × 900; 4:3 crop |
| About circle | `family.svg` | 640 × 640; circle crop |
| Education & Learning card | `learning.svg` | 960 × 640; approximately 1.7:1 crop |
| Health & Well-being card | `wellbeing.svg` | 960 × 640; approximately 1.7:1 crop |
| Faith & Community card | `fellowship.svg` | 960 × 640; approximately 1.7:1 crop |
| Growing Together Through Care | `community.svg` | 1200 × 680; responsive crop |
| Learning Activities gallery | `learning.svg` | 960 × 640; approximately 2:1 crop |
| Community Fellowship gallery | `fellowship.svg` | 960 × 640; approximately 2:1 crop |
| Family Engagement gallery | `family.svg` | 960 × 640; approximately 2:1 crop |
| Contact background | `contact.svg` | 1200 × 680; subject on right; dark overlay retained |

Place final photos in the public image directory, update their references in
`welcome.blade.php` (including the small program/gallery arrays), and set correct
intrinsic dimensions and descriptive alt text. Remove “photo coming soon” notes
only for replaced images. Keep their actual file formats; do not rename a JPEG
to PNG. Hero and contact backgrounds are decorative and retain empty alt text.
No event images or schedules are required for the announcements empty state.

The preview supplies the organization name, Daraga/Albay context, hero and section
headings, Our Story paragraph, values, short card descriptions, and care paragraph.
There was no separate approved mission or vision text in the source. These are
explicit editorial drafts requiring owner review:

- **Our Mission:** “To walk alongside children and families through faith,
  learning, and community care — nurturing growth, encouraging well-being,
  and sharing God’s love in everyday life.”
- **Our Vision:** “A caring community where children can learn, families can
  flourish, and hope can grow. Together, we look toward a brighter future rooted
  in faith and compassion.”
- The three expanded “Our approach” paragraphs, “Walking alongside families”
  eyebrow, and footer's “Rooted in faith. Growing together in care.” are draft
  descriptive copy, not claims about specific services or impact.
- Contact availability and announcements text are honest interim notices.
  Replace only after approved public contact details or announcements exist.
  Operational `ProgramActivity` data is not a public content source.

Verification performed on 30 October 2026:

| Check | Result |
| --- | --- |
| `node scripts/build-public-assets.mjs` | Production build passed using the existing pipeline, without loading project environment files. No dependencies added. |
| `php vendor/bin/phpunit tests/Feature/PublicHomepageTest.php --no-progress` | 13 tests, 207 assertions passed. Root renders; all destinations/assets exist; login/register still render with staff assets; ten protected endpoints redirect guests and return 401 for guest JSON. A query callback fails any attempted database query. |
| `php vendor/bin/phpunit tests/Unit/TestIsolationTest.php --no-progress` | 29 tests, 33 assertions passed. |
| `node --test tests/js/*.test.mjs` | All 144 existing JavaScript tests passed. |
| `node --check` on the homepage module, browser test, and build helper | Passed. |
| `php vendor/bin/pint --test tests/Feature/PublicHomepageTest.php` | Passed. |
| Edge browser test | All eight tests passed (parent and seven checks). Layout at 1440, 1024, 768, 390, and 320px; no horizontal overflow; images/font assets load; menu/tabs/disclosures work by keyboard; links resolve; reduced motion and JavaScript-disabled fallbacks work; no console errors or unexpected requests. |
| CSS/JS separation | Auth responses exclude homepage bundles; browser scope probe retains the same computed styles and receives no initialization when homepage bundles are loaded without a homepage root. |
| Source preservation | SHA-256 comparison confirmed all 153 pre-existing files under `app`, `routes`, and `resources`, excluding the intentionally replaced welcome view, remain unchanged. |
| Contrast | White/red 6.47:1, charcoal/gold 10.89:1, brown/ivory 8.24:1, body gray/ivory 7.07:1. Placeholder labels use brown. |
| Visual review | Desktop and mobile full-page screenshots reviewed; tablet capture supplied. Overlapping image sizes, emblem presentation, alignment, and section order checked. |

The build reports the pre-existing optional `fontaine` optimized-fallback warning;
it completes successfully and no package was installed. There is no full
screen-reader audit or physical-device test. Existing staff views and business
logic were left intact; authenticated staff screens were not opened against live
data. No migrations, emails, queue operations, worker restarts, live records,
live cache, private storage, `.env`, or cached application configuration were
accessed. The isolated harness uses fresh OS-temporary storage and configuration,
array services, fake mail/notifications, and guarded SQLite `:memory:`; this test
class never creates a schema.

Screenshots are isolated renders of the real Blade page and production assets:
[desktop](qa/public-homepage/desktop.png), [tablet](qa/public-homepage/tablet.png),
[mobile](qa/public-homepage/mobile.png). Browser results are in
`qa/public-homepage/browser-results.json`.

The existing Laravel process was verified listening on `0.0.0.0:8000`, so the
local homepage URL is **http://localhost:8000/**. No request was sent to that live
process. The current LAN address was not verified.

To reproduce browser checks in PowerShell with the existing Edge installation,
build first and create fixtures through the guarded test harness:

```powershell
node scripts/build-public-assets.mjs
$homepageFixtures = Join-Path ([IO.Path]::GetTempPath()) ('rlcp-public-ui-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $homepageFixtures | Out-Null
$env:HOMEPAGE_UI_FIXTURES = $homepageFixtures
php vendor/bin/phpunit tests/Feature/PublicHomepageTest.php --no-progress
$env:HOMEPAGE_UI_BROWSER = 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
node --test tests/browser/public-homepage.test.mjs
# After inspection, delete only this generated temporary fixture directory.
$resolvedFixture = (Resolve-Path -LiteralPath $homepageFixtures).Path
$resolvedTemp = (Resolve-Path -LiteralPath ([IO.Path]::GetTempPath())).Path.TrimEnd('\')
if ([IO.Path]::GetDirectoryName($resolvedFixture) -eq $resolvedTemp -and [IO.Path]::GetFileName($resolvedFixture).StartsWith('rlcp-public-ui-')) {
    Remove-Item -LiteralPath $resolvedFixture -Recurse -Force
}
Remove-Item Env:HOMEPAGE_UI_FIXTURES, Env:HOMEPAGE_UI_BROWSER
```
