'use strict'
// Every mutant of the S10b proofs: [id, file, anchor (exactly once), replacement, what it breaks]. Run with runner.cjs from the g2gv0 root.
const MODEL = 'lib/outreach/model.ts'
const CHECKS = 'components/domain/gtm/outreach-checks.tsx'
const CLAIMS = 'components/domain/gtm/outreach-claims.tsx'
const VIEW = 'components/domain/gtm/outreach-view.tsx'
const SERVICE = 'services/outreach/gate-service.ts'
const TONES = 'lib/outreach/tones.ts'

const rows = [
  // ── model.ts: reading the answer, fail closed
  ['M01', MODEL, `passed: r.passed === true`, `passed: r.passed !== false`, 'a check passes on anything but an explicit false'],
  ['M02', MODEL, `if (!r) return { key: 'unknown', label: '', passed: false, detail: '' }`, `if (!r) return { key: 'unknown', label: '', passed: true, detail: '' }`, 'an unreadable item reads as passed'],
  ['M03', MODEL, `if (total === 0) return { total, failed, passed: false,`, `if (total === 0) return { total, failed, passed: true,`, 'an empty list passes'],
  ['M04', MODEL, `if (failed > 0) return`, `if (failed > 99) return`, 'failed checks no longer fail the run'],
  ['M05', MODEL, `if (serverPassed === false) return`, `if (serverPassed === true) return`, 'a server verdict of not-passed is ignored'],
  ['M06', MODEL, `s'} failed: you cannot send this yet.`, `s'} failed.`, 'the summary stops saying you cannot send yet'],
  ['M07', MODEL, `total === 1 ? '' : 's'}`, `total === 1 ? 's' : ''}`, 'singular and plural swapped'],
  ['M08', MODEL, `return [...checks.filter((c) => !c.passed), ...checks.filter((c) => c.passed)]`, `return [...checks]`, 'failed checks no longer first'],
  ['M09', MODEL, `return [...checks.filter((c) => !c.passed), ...checks.filter((c) => c.passed)]`, `return [...checks.filter((c) => c.passed), ...checks.filter((c) => !c.passed)]`, 'passed checks first'],
  ['M10', MODEL, `Object.prototype.hasOwnProperty.call(CHECK_SENTENCES, check.key) ? CHECK_SENTENCES[check.key] : null`, `CHECK_SENTENCES[check.key] ?? null`, 'a key like constructor is taken for a known check'],
  ['M11', MODEL, `if (known) return check.passed ? known.passed : known.failed`, `if (known) return check.passed ? known.failed : known.passed`, 'the passed and failed sentences swapped'],
  ['M12', MODEL, `check.passed ? 'Passed' : 'Failed'`, `check.passed ? 'Failed' : 'Passed'`, 'the verdict words swapped'],
  ['M13', MODEL, `c.key === 'unapproved_claim' && !c.passed`, `c.key === 'unapproved_claim'`, 'claims wanted even when the claim check passed'],
  ['M14', MODEL, `textOf(message.contact_email), `, ``, 'the address is not part of the message signature'],
  ['M15', MODEL, `textOf(message.body), `, ``, 'the text is not part of the message signature'],
  ['M16', MODEL, `['draft', 'rejected', 'pending_approval', 'approved', 'failed']`, `['draft', 'rejected', 'pending_approval', 'approved', 'failed', 'sent']`, 'a sent message gets checks'],
  ['M17', MODEL, `return status === 422 && error.message.startsWith(GATE_REFUSAL_PREFIX) ? error.message : null`, `return error.message.startsWith(GATE_REFUSAL_PREFIX) ? error.message : null`, 'the 422 status is not required for a refusal'],
  ['M18', MODEL, `return status === 422 && error.message.startsWith(GATE_REFUSAL_PREFIX) ? error.message : null`, `return status === 422 ? error.message : null`, 'any 422 is taken for a gate refusal'],
  ['M19', MODEL, `if (code === 'gate_blocked') return error.message`, ``, 'an error that carries the code is not recognised'],
  ['M20', MODEL, `return code === 'gate_blocked' ? 'Held back`, `return code ? 'Held back`, 'every coded attempt is called held back'],
  ['M21', MODEL, `if (status === 404) return { kind: 'unavailable', message: UNAVAILABLE_TEXT }`, `if (status === 405) return { kind: 'unavailable', message: UNAVAILABLE_TEXT }`, '404 is not "not available"'],
  ['M22', MODEL, `if (status === 403) return { kind: 'unavailable', message: FORBIDDEN_TEXT }`, `if (status === 403) return { kind: 'error', message: FORBIDDEN_TEXT }`, '403 is a retryable error'],
  ['M23', MODEL, `if (status === 429) return { kind: 'busy', message: BUSY_TEXT }`, `if (status === 429) return { kind: 'error', message: BUSY_TEXT }`, '429 is not "busy"'],
  ['M24', MODEL, `claim === null || r.status !== 'approved') continue`, `claim === null) continue`, 'a claim that is not approved is listed as approved'],
  ['M25', MODEL, `data.total >= claims.length`, `data.total >= 0`, 'a total smaller than what came back is believed'],
  ['M26', MODEL, `return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null`, `return url.href`, 'any address becomes a link'],
  ['M27', MODEL, `if (!data || !Array.isArray(data.checks)) return null`, `if (!data) return null`, 'a body with no checks list reads as an empty run, not as unreadable'],
  ['M28', MODEL, `Number.isSafeInteger(n) && n >= 1`, `Number.isSafeInteger(n) && n >= 0`, 'product id 0 accepted'],
  ['M29', MODEL, `failed: 'The message states a claim nobody has approved.'`, `failed: 'The message states a claim.'`, 'the claim sentence loses its meaning'],
  ['M30', MODEL, `if (status === 403) return { kind: 'unavailable', message: CLAIMS_FORBIDDEN_TEXT }`, `if (status === 402) return { kind: 'unavailable', message: CLAIMS_FORBIDDEN_TEXT }`, 'claims 403 not recognised'],
  ['M31', MODEL, `if (status === 404) return { kind: 'unavailable', message: CLAIMS_UNAVAILABLE_TEXT }`, `if (status === 405) return { kind: 'unavailable', message: CLAIMS_UNAVAILABLE_TEXT }`, 'claims 404 not recognised'],
  ['M32', MODEL, `return { checks, passed: summarize(checks, serverPassed).passed, checkedAt: readableText(data.checked_at) }`, `return { checks, passed: checks.every((c) => c.passed), checkedAt: readableText(data.checked_at) }`, 'the report ignores the server verdict and an empty list'],

  // ── outreach-checks.tsx
  ['C01', CHECKS, `if (ran.current !== null && trigger <= ran.current) return`, `if (ran.current === trigger) return`, 'clearing a refusal asks the server again (the defect the test found)'],
  ['C02', CHECKS, `queueMicrotask(() => void check())`, `void 0`, 'no check on open'],
  ['C03', CHECKS, `if (ran.current !== null && trigger <= ran.current) return`, ``, 'no guard against asking again'],
  ['C04', CHECKS, `data-checks-summary={summary.passed ? 'passed' : 'failed'}`, `data-checks-summary="passed"`, 'a failed run is marked passed'],
  ['C05', CHECKS, `disabled={busy || (phase.kind === 'ready' && dirty)}`, `disabled={busy}`, 'Check again is offered over unsaved edits'],
  ['C06', CHECKS, `disabled={busy || (phase.kind === 'ready' && dirty)}`, `disabled={phase.kind === 'ready' && dirty}`, 'Check again can be pressed while a check runs'],
  ['C07', CHECKS, `const stale = phase.kind === 'ready' && phase.signature !== signature`, `const stale = false`, 'an out-of-date report is never marked'],
  ['C08', CHECKS, `orderChecks(report.checks).map(`, `report.checks.map(`, 'the list is not ordered failing first'],
  ['C09', CHECKS, `{needsClaims(report.checks) && <OutreachClaims`, `{true && <OutreachClaims`, 'claims are listed on every run'],
  ['C10', CHECKS, `const retryable = phase.kind === 'failed' && phase.failure.kind !== 'unavailable'`, `const retryable = phase.kind === 'failed'`, 'Retry offered when the server has no checks'],
  ['C11', CHECKS, `if (seq === asked.current) setPhase(report ?`, `if (seq >= 0) setPhase(report ?`, 'a late older answer replaces a newer one'],
  ['C12', CHECKS, `if (seq === asked.current) setBusy(false)`, `setBusy(false)`, 'a late older answer ends the wait of a newer ask'],
  ['C13', CHECKS, `const readFor = current.current`, `const readFor = signature`, 'the report is stamped with the signature of the first render'],
  ['C14', CHECKS, `{refusal && (`, `{false && (`, 'the refusal is not shown'],
  ['C15', CHECKS, `<div role="alert" data-checks-refusal`, `<div data-checks-refusal`, 'the refusal is not announced'],
  ['C16', CHECKS, `{phase.kind === 'loading' && <p role="status" data-checks-loading`, `{false && <p role="status" data-checks-loading`, 'no loading state'],
  ['C17', CHECKS, `{report.checkedAt && <p`, `{false && <p`, 'no time of the check'],
  ['C18', CHECKS, `api.checks(GTM_MESSAGES, messageId)`, `api.checks('crm_emails', messageId)`, 'asks about the wrong table'],
  ['C19', CHECKS, `{phase.kind === 'ready' ? 'Check again' : 'Retry'}`, `{phase.kind === 'ready' ? 'Retry' : 'Check again'}`, 'button labels swapped'],
  ['C20', CHECKS, `{dirty && <p data-checks-unsaved`, `{false && <p data-checks-unsaved`, 'no note about unsaved edits'],
  ['C21', CHECKS, `{!dirty && stale && <p data-checks-stale`, `{false && <p data-checks-stale`, 'no out-of-date note'],
  ['C22', CHECKS, `<CheckCircle2 aria-hidden="true"`, `<CheckCircle2`, 'a passed icon is read out'],
  ['C23', CHECKS, `{busy && <p role="status" data-checks-busy`, `{false && <p role="status" data-checks-busy`, 'no note while checking again'],
  ['C24', CHECKS, `<strong>{verdict(c)}:</strong>`, `<strong></strong>`, 'the verdict word is not shown'],
  ['C25', CHECKS, `{c.detail !== '' && <p`, `{false && <p`, 'the server\'s reason is not shown'],

  // ── outreach-claims.tsx
  ['K01', CLAIMS, `const link = httpUrl(c.sourceUrl)`, `const link = c.sourceUrl`, 'a javascript: source becomes a link'],
  ['K02', CLAIMS, `rel="noopener noreferrer" `, ``, 'the link opens without rel'],
  ['K03', CLAIMS, `{phase.failure.kind !== 'unavailable' && (`, `{true && (`, 'Retry offered for a claims list the person may not read'],
  ['K04', CLAIMS, `{productId === null && c.productName ?`, `{c.productName ?`, 'the product is named even when the list is of one product'],
  ['K05', CLAIMS, `{phase.list.total > phase.list.claims.length &&`, `{phase.list.total >= phase.list.claims.length &&`, '"showing n of n" line when all are shown'],
  ['K06', CLAIMS, `readClaims(await api.approvedClaims(productId))`, `readClaims(await api.approvedClaims(null))`, 'the product is not asked for'],
  ['K07', CLAIMS, `{phase.kind === 'ready' && phase.list.claims.length === 0 &&`, `{false &&`, 'no empty-list text'],

  // ── outreach-view.tsx (the three small edits)
  ['V01', VIEW, `if (refused) setRefusal({ seq: ++refusals.current, message: refused })`, `if (false) setRefusal({ seq: ++refusals.current, message: refused })`, 'a gate refusal is never shown as one'],
  ['V02', VIEW, `else setError(errMsg(e))`, `else void 0`, 'every other error is swallowed'],
  ['V03', VIEW, `setRefusal(null)`, `void 0`, 'a refusal outlives the next action'],
  ['V04', VIEW, `{isCheckable(m.status) && <OutreachChecks`, `{true && <OutreachChecks`, 'checks on finished messages'],
  ['V05', VIEW, `signature={messageSignature(m)}`, `signature=""`, 'a changed message is never marked'],
  ['V06', VIEW, `dirty={dirty} productId`, `dirty={false} productId`, 'unsaved edits are not passed on'],
  ['V07', VIEW, `productId={messageProductId(m)}`, `productId={null}`, 'the message\'s product is not passed on'],
  ['V08', VIEW, `{attemptNote(a.code) && <span data-gate-blocked>{attemptNote(a.code)}</span>}`, `{a.code && <span data-gate-blocked>{a.code}</span>}`, 'every coded attempt is called held back'],
  ['V09', VIEW, `refusal={refusal} />`, `refusal={null} />`, 'the refusal is not handed to the section'],
  ['V10', VIEW, `const refused = gateRefusalMessage(e)`, `const refused: string | null = null`, 'the refusal is never recognised'],

  // ── the no-send scan must see these (a send path, a send button, a flag, a form part, a changed control of the screen)
  ['S01', SERVICE, `export const gateService: GateApi = {`, `export const leak = () => apiClient.post<unknown>('/gtm/outreach/1/send', {})\n\nexport const gateService: GateApi = {`, 'a POST to a send route in the service'],
  ['S02', CHECKS, `<h3 className="text-sm font-semibold text-foreground">Checks</h3>`, `<h3 className="text-sm font-semibold text-foreground">Checks</h3><button type="button">Send now</button>`, 'a Send now button in the section'],
  ['S03', CHECKS, `import { fmtDate } from './gtm-shared'`, `import { fmtDate } from './gtm-shared'\nimport { outreachService } from '@/services/gtm/gtm'`, 'the sending service imported'],
  ['S04', MODEL, `const record = (value: unknown)`, `export const SWITCH = 'gtm_sending_enabled'\nconst record = (value: unknown)`, 'a sending flag named'],
  ['S05', CLAIMS, `<h4 className="mb-1 text-sm font-semibold text-foreground">`, `<input /><h4 className="mb-1 text-sm font-semibold text-foreground">`, 'a field in the claims list'],
  ['S06', VIEW, `{m.sent_at && <p className="text-xs text-muted-foreground">Sent {fmtDate(m.sent_at, true)}.</p>}`, `{m.sent_at && <p className="text-xs text-muted-foreground">Sent {fmtDate(m.sent_at, true)}.</p>}<Button size="sm">Send all</Button>`, 'another button on the screen'],
  ['S07', VIEW, `outreachService.send(m.id)`, `outreachService.approve(m.id)`, 'the Send now handler calls another route'],
  ['S08', VIEW, `This cannot be undone.`, `Go.`, 'the Send now confirmation changes'],

  // ── contrast and phone width
  ['T01', TONES, `neutral: 'border-border bg-card text-foreground',`, `neutral: 'border-border bg-muted/50 text-muted-foreground',`, 'a tint of the muted colour as a surface'],
  ['T02', CHECKS, `text-xs font-medium text-foreground hover:bg-muted disabled:opacity-50`, `text-xs font-medium text-primary hover:bg-muted disabled:opacity-50`, 'the button text takes a status colour'],
  ['T03', CHECKS, `<p className="break-words text-xs text-muted-foreground">{c.detail}</p>`, `<p className="whitespace-nowrap text-xs text-muted-foreground">{c.detail}</p>`, 'a long detail cannot wrap'],
  ['T04', CHECKS, `<div className="min-w-0">`, `<div>`, 'the text box cannot shrink next to the icon'],
  ['T05', CLAIMS, `<ul className="space-y-1.5">`, `<table /><ul className="space-y-1.5">`, 'a table in the claims list'],
  ['T06', CHECKS, `<h3 className="text-sm font-semibold text-foreground">Checks</h3>`, `<h3 className="text-sm font-semibold text-destructive">Checks</h3>`, 'the heading in a status colour'],
]

module.exports = rows.map(([id, file, find, replace, what]) => ({ id, file, find, replace, what }))
