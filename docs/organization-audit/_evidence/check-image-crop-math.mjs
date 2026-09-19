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

import { coverScale, panLimit, clampOffset, placement, outputType }
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
console.log('2. The frame is ALWAYS covered - no empty corner, ever')
console.log('════════════════════════════════════════════════════════════════')
{
  // The original complaint is a logo that does not fit. The opposite failure -
  // a frame with a transparent wedge in one corner - would be worse, because it
  // looks like a rendering bug rather than a framing choice.
  const images = [[4032, 3024], [3024, 4032], [1000, 1000], [6000, 1200], [200, 640]]
  let breaches = 0
  let tested = 0

  for (const [nw, nh] of images) {
    for (let zoom = 1; zoom <= 4.01; zoom += 0.25) {
      // Deliberately absurd offsets: the clamp inside `placement` must absorb them.
      for (const offset of [
        { x: 0, y: 0 }, { x: 5, y: 5 }, { x: -5, y: -5 },
        { x: 0.49, y: -0.49 }, { x: -12.7, y: 3.3 },
      ]) {
        const frame = 512
        const p = placement(nw, nh, frame, zoom, offset)
        tested += 1

        // Covered means: left edge at or left of 0, right edge at or right of
        // the frame, and the same vertically. A 1e-9 slack for float noise.
        const covered =
          p.x <= 1e-9 &&
          p.y <= 1e-9 &&
          p.x + p.width >= frame - 1e-9 &&
          p.y + p.height >= frame - 1e-9

        if (!covered) {
          breaches += 1
          if (breaches <= 3) {
            bad(`${nw}x${nh} zoom ${zoom.toFixed(2)} offset ${JSON.stringify(offset)} leaves a gap: ${JSON.stringify(p)}`)
          }
        }
      }
    }
  }

  check(breaches === 0, `${tested} placements across 5 aspect ratios, every one covers the frame`,
    `${breaches} left a gap`)
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
}

console.log()
console.log('════════════════════════════════════════════════════════════════')
console.log(`  ${correct} CORRECT, ${wrong} WRONG`)
console.log('════════════════════════════════════════════════════════════════')

process.exit(wrong === 0 ? 0 : 1)
