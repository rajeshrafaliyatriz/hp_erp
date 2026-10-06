/**
 * THE CROP MATHS, CHECKED NUMERICALLY.
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS EXISTS TO CATCH
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * A cropper has one way to betray somebody: the picture they framed and the
 * picture that gets saved are not the same picture. Every other defect is visible
 * while you use it. That one is only visible afterwards, on somebody else's
 * screen, and by then the original file is gone.
 *
 * The design guards against it by producing the preview and the export from ONE
 * function at two different frame sizes. This file is the proof that the function
 * behaves the same at both: the same zoom and offset must select the same REGION
 * OF THE SOURCE IMAGE whether the frame is 288 pixels or 512.
 *
 * It also checks the properties that matter for the complaint that prompted the
 * feature - "the logo is cut off" - namely that the frame is always fully
 * covered, that panning cannot open a gap, and that a square image at zoom 1 has
 * nothing to pan because it already fits exactly.
 *
 * Run:  node Docs/organization-audit/_evidence/check-image-crop-math.mjs
 */

import { coverScale, containZoom, covers, panLimit, clampOffset, placement, outputType }
  from '../../../../g2gv0/lib/image-crop.ts'

let correct = 0
let wrong = 0

function ok(message) {
  console.log('  CORRECT  ' + message)
  correct += 1
}

function bad(message) {
  console.log('  WRONG    ' + message)
  wrong += 1
}

function check(condition, message, detail = '') {
  if (condition) ok(message)
  else bad(message + (detail ? '  -> ' + detail : ''))
}

/** Floating point: 1e-9 is far tighter than a pixel, and nothing here is chaotic. */
const near = (a, b, tol = 1e-9) => Math.abs(a - b) <= tol

/**
 * Which region of the SOURCE the frame is showing, in source pixels.
 *
 * This is the number that has to be frame-size independent. It is derived from
 * `placement` rather than computed separately, so it cannot drift from it.
 */
function sourceRegion(naturalWidth, naturalHeight, frame, zoom, offset) {
  const p = placement(naturalWidth, naturalHeight, frame, zoom, offset)
  const scale = p.width / naturalWidth

  return {
    x: -p.x / scale,
    y: -p.y / scale,
    width: frame / scale,
    height: frame / scale,
  }
}

console.log('════════════════════════════════════════════════════════════════')
console.log('1. The preview and the export select the SAME source region')
console.log('════════════════════════════════════════════════════════════════')
{
  // A landscape phone photo, a portrait one, a square one, and a panorama.
  const images = [
    [4032, 3024, 'landscape 4:3'],
    [3024, 4032, 'portrait 3:4'],
    [1000, 1000, 'square'],
    [6000, 1200, 'panorama 5:1'],
    [200, 640, 'tall and small'],
  ]
  const states = [
    [1, { x: 0, y: 0 }, 'untouched'],
    [1, { x: 0.3, y: 0 }, 'panned right'],
    [1, { x: -0.2, y: 0.15 }, 'panned down-left'],
    [2.5, { x: 0.1, y: -0.4 }, 'zoomed and panned'],
    [4, { x: 0, y: 0 }, 'zoomed to the limit'],
  ]

  for (const [nw, nh, name] of images) {
    for (const [zoom, offset, what] of states) {
      const preview = sourceRegion(nw, nh, 288, zoom, offset)
      const exported = sourceRegion(nw, nh, 512, zoom, offset)

      const same =
        near(preview.x, exported.x, 1e-6) &&
        near(preview.y, exported.y, 1e-6) &&
        near(preview.width, exported.width, 1e-6) &&
        near(preview.height, exported.height, 1e-6)

      check(
        same,
        `${name}, ${what}: 288px frame and 512px frame agree`,
        `preview ${JSON.stringify(preview)} vs export ${JSON.stringify(exported)}`,
      )
    }
  }
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log('2. Two regimes: covered above zoom 1, contained below it')
console.log('════════════════════════════════════════════════════════════════')
{
  /*
   * THIS SECTION USED TO ASSERT THE WRONG INVARIANT.
   *
   * It required the frame to be covered at EVERY zoom, and it passed, because the
   * cropper would not go below zoom 1. That made the test agree with a cropper
   * that could not do the one thing it was most needed for: a 1000x300 logo opened
   * showing its middle square and there was no way to fit the whole thing. The
   * report was "the image is too big to fit".
   *
   * The real property has two halves, and asserting only the first is what hid the
   * defect:
   *
   *   zoom >= 1   the frame is fully covered - no empty corner, whatever the pan
   *   zoom < 1    the image is fully INSIDE the frame - it can never be pushed out
   */
  const images = [[4032, 3024], [3024, 4032], [1000, 1000], [6000, 1200], [200, 640]]
  const absurd = [
    { x: 0, y: 0 }, { x: 5, y: 5 }, { x: -5, y: -5 },
    { x: 0.49, y: -0.49 }, { x: -12.7, y: 3.3 },
  ]
  const frame = 512

  let coveredBreaches = 0
  let containedBreaches = 0
  let tested = 0

  for (const [nw, nh] of images) {
    // Deliberately spans both regimes, including the 0.1 floor the UI now allows.
    for (let zoom = 0.1; zoom <= 4.01; zoom += 0.1) {
      for (const offset of absurd) {
        const p = placement(nw, nh, frame, zoom, offset)
        tested += 1

        if (covers(zoom)) {
          const covered =
            p.x <= 1e-9 && p.y <= 1e-9 &&
            p.x + p.width >= frame - 1e-9 && p.y + p.height >= frame - 1e-9

          if (!covered) {
            coveredBreaches += 1
            if (coveredBreaches <= 2) {
              bad(`${nw}x${nh} zoom ${zoom.toFixed(2)} leaves a gap: ${JSON.stringify(p)}`)
            }
          }
        } else {
          // Below cover the image is smaller than the frame in at least one axis.
          // It must stay inside: no part of it drawn beyond the frame's edges.
          const inside =
            p.x >= -1e-9 - Math.max(0, p.width - frame) &&
            p.y >= -1e-9 - Math.max(0, p.height - frame) &&
            p.x + p.width <= frame + 1e-9 + Math.max(0, p.width - frame) &&
            p.y + p.height <= frame + 1e-9 + Math.max(0, p.height - frame)

          if (!inside) {
            containedBreaches += 1
            if (containedBreaches <= 2) {
              bad(`${nw}x${nh} zoom ${zoom.toFixed(2)} escapes the frame: ${JSON.stringify(p)}`)
            }
          }
        }
      }
    }
  }

  check(coveredBreaches === 0,
    `at zoom >= 1, every placement covers the frame (${tested} tested across both regimes)`,
    `${coveredBreaches} left a gap`)

  check(containedBreaches === 0,
    'at zoom < 1, the image never escapes the frame however hard it is dragged',
    `${containedBreaches} escaped`)
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log('2b. THE WHOLE IMAGE FITS - the thing that was impossible')
console.log('════════════════════════════════════════════════════════════════')
{
  /*
   * The direct regression test for the report. At `containZoom` the longest side
   * exactly fills the frame, so every pixel of the source is inside it.
   */
  for (const [nw, nh, name] of [
    [1000, 300, 'a wide logo 10:3'],
    [4032, 3024, 'a 4:3 photo'],
    [3024, 4032, 'a portrait photo'],
    [6000, 1200, 'a panorama 5:1'],
    [800, 800, 'a square'],
  ]) {
    const zoom = containZoom(nw, nh)
    const p = placement(nw, nh, 512, zoom, { x: 0, y: 0 })

    const whollyVisible =
      p.x >= -1e-6 && p.y >= -1e-6 &&
      p.x + p.width <= 512 + 1e-6 && p.y + p.height <= 512 + 1e-6

    const touches =
      near(Math.max(p.width, p.height), 512, 1e-6)

    check(whollyVisible && touches,
      `${name}: at containZoom ${zoom.toFixed(3)} the whole image fits and fills one axis`,
      JSON.stringify(p))
  }

  // And a square is the only shape where fitting and covering are the same thing.
  check(near(containZoom(500, 500), 1), 'a square image has containZoom exactly 1')
  check(containZoom(1000, 300) < 1, 'a non-square image has containZoom below 1 - which the old floor forbade')

  /*
   * A ZOOMED-OUT IMAGE CAN STILL BE MOVED.
   *
   * A mutation test found this missing: restoring the old `Math.max(0, ...)` pan
   * clamp - which pins anything smaller than the frame to the centre - broke
   * nothing, because every other assertion here only ever checks that the image
   * stays INSIDE the frame, and a centred image trivially does.
   *
   * Being able to place a logo off-centre inside the frame is the whole point of
   * the second regime, so it is asserted directly.
   */
  const small = panLimit(1000, 300, 512, 0.2)
  check(small.x > 0 && small.y > 0,
    'below cover there is room to move the image inside the frame',
    JSON.stringify(small))

  const centred = placement(1000, 300, 512, 0.2, { x: 0, y: 0 })
  const shifted = placement(1000, 300, 512, 0.2, { x: 0.1, y: -0.1 })
  check(!near(centred.x, shifted.x) && !near(centred.y, shifted.y),
    'and an offset actually moves it, rather than being clamped away',
    `centred ${centred.x},${centred.y} vs shifted ${shifted.x},${shifted.y}`)
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log('3. A square image at zoom 1 has nothing to pan')
console.log('════════════════════════════════════════════════════════════════')
{
  const limit = panLimit(800, 800, 512, 1)
  check(near(limit.x, 0) && near(limit.y, 0),
    'a square image at zoom 1 reports no pan room',
    JSON.stringify(limit))

  // And the UI is entitled to rely on that to disable the drag surface.
  const landscape = panLimit(1600, 800, 512, 1)
  check(landscape.x > 0 && near(landscape.y, 0),
    'a 2:1 image pans horizontally only',
    JSON.stringify(landscape))

  const portrait = panLimit(800, 1600, 512, 1)
  check(near(portrait.x, 0) && portrait.y > 0,
    'a 1:2 image pans vertically only',
    JSON.stringify(portrait))

  const zoomed = panLimit(800, 800, 512, 2)
  check(zoomed.x > 0 && zoomed.y > 0,
    'zooming a square image opens pan room in both axes',
    JSON.stringify(zoomed))
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log('4. Untouched, a photo is centred - which is what people expect')
console.log('════════════════════════════════════════════════════════════════')
{
  // Opening the cropper must show the middle of the picture, not a corner.
  for (const [nw, nh, name] of [[4032, 3024, 'landscape'], [3024, 4032, 'portrait']]) {
    const region = sourceRegion(nw, nh, 512, 1, { x: 0, y: 0 })
    const centreX = region.x + region.width / 2
    const centreY = region.y + region.height / 2

    check(near(centreX, nw / 2, 1e-6) && near(centreY, nh / 2, 1e-6),
      `${name} at zoom 1 is centred on the image's own centre`,
      `got ${centreX},${centreY} want ${nw / 2},${nh / 2}`)
  }
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log('5. Zooming in shows LESS of the picture, never more')
console.log('════════════════════════════════════════════════════════════════')
{
  // A cropper whose zoom goes the wrong way is a cropper nobody can use, and it
  // is a single sign error away at all times.
  let monotone = true
  let previous = Infinity

  for (let zoom = 1; zoom <= 4.01; zoom += 0.25) {
    const region = sourceRegion(4032, 3024, 512, zoom, { x: 0, y: 0 })
    if (region.width > previous + 1e-9) monotone = false
    previous = region.width
  }

  check(monotone, 'source region shrinks monotonically as zoom increases')

  const one = sourceRegion(4032, 3024, 512, 1, { x: 0, y: 0 })
  const four = sourceRegion(4032, 3024, 512, 4, { x: 0, y: 0 })
  check(near(one.width / four.width, 4, 1e-6),
    'zoom 4 shows exactly a quarter of the width of zoom 1',
    `ratio ${one.width / four.width}`)
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log('6. Transparency survives, and the server will accept the type')
console.log('════════════════════════════════════════════════════════════════')
{
  // `AccountController` accepts jpg, jpeg, png, gif, webp. Anything this returns
  // has to be in that set or the upload 422s after the person has done the work.
  const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp']

  check(outputType('image/png').mime === 'image/png',
    'a PNG stays a PNG, so a transparent logo does not gain a black background')
  check(outputType('image/gif').mime === 'image/png',
    'a GIF becomes a PNG (one frame; the UI warns before committing)')
  check(outputType('image/webp').mime === 'image/webp', 'WEBP stays WEBP')
  check(outputType('image/jpeg').mime === 'image/jpeg', 'a JPEG stays a JPEG')

  const all = ['image/png', 'image/gif', 'image/webp', 'image/jpeg', 'image/bmp', '']
    .map((t) => outputType(t).mime)
  check(all.every((m) => ACCEPTED.includes(m)),
    'every output type is one the server accepts',
    all.join(', '))

  /*
   * AN UNCOVERED FRAME IS ALWAYS PNG.
   *
   * Another gap a mutation test found: deleting the `!frameFilled` branch entirely
   * broke nothing here, because every case above passed the default `true`. Zoomed
   * out, the space around the image is genuinely empty - exported as JPEG it comes
   * back BLACK, so the crop that was meant to stop a logo being cut off would
   * instead return a black square with the logo floating in the middle of it.
   */
  const unfilled = ['image/jpeg', 'image/webp', 'image/png', 'image/gif', '']
    .map((t) => outputType(t, false).mime)
  check(unfilled.every((m) => m === 'image/png'),
    'with bare frame, every input exports as PNG so the space stays transparent',
    unfilled.join(', '))

  check(outputType('image/jpeg', true).mime === 'image/jpeg',
    'and a filled frame still keeps JPEG, which is much smaller for a photograph')
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log(`  ${correct} CORRECT, ${wrong} WRONG`)
console.log('════════════════════════════════════════════════════════════════')

process.exit(wrong === 0 ? 0 : 1)
