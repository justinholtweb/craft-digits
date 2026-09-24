# Plugin Store promo images

Marketing images for the Digits listing on the Craft Plugin Store, rendered in the same theme as the
plugin's marketing page at
[justinholt.com/plugins/craft-digits](https://justinholt.com/plugins/craft-digits).

## Building

```bash
./build.sh          # all slides
./build.sh "2 5"    # just slides 2 and 5
```

Output lands in `out/` as `digits-promo-N.jpg`, 1920×1080 (rendered at 2× in headless Chrome, then
downsampled so the type stays crisp). **Promos are JPEG, never PNG** — Chrome can only write PNG, so
`build.sh` converts with `sips` at quality 90 and deletes the intermediate. `fonts.css` is generated
and gitignored.

## Slides

| # | Slide | Shows |
|---|-------|-------|
| 1 | Cover — name, tagline, app icon, price | — |
| 2 | Links you can take back | a link's life from order to refund, ending refused |
| 3 | Two tabs, one download left | the conditional `UPDATE` in `Licenses::spend()` and its two outcomes |
| 4 | Every licence at a glance | **real** screenshot of the licences index |
| 5 | Keys your software can check | the activation API: first activation, a reinstall, a fourth machine |
| 6 | Every seat, one screen | **real** screenshot of a licence with three activations |
| 7 | Everything after the sale | versions, portal, VAT, Commerce optional |

Slides 3 and 5 quote the code, so they have to agree with it: the `UPDATE` compares against the
*effective* limit as a bound value, and a successful activation answers `"code": "ok"` with the seat
count under `license.activationCount` / `license.activationLimit`.

The screenshots in `shots/` come from the plugin-testing harness via `~/Sites/plugin-shots`
(`specs/digits.json`), against demo data rather than the suite's fixtures.

The watermark is the glyph from `src/icon-mask.svg` with the tile stripped — at watermark scale a
rounded square reads as a grey box across the slide.
