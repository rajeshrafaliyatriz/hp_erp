{{--
    The offer email a candidate receives.

    ── WHAT THIS DELIBERATELY DOES NOT CONTAIN ────────────────────────────────

    NO CREDENTIALS. At this point the person is a candidate, not an employee -
    they have no account, and creating one before they accept would put a login
    in the hands of somebody who may decline. The account and its invite are
    issued by EmployeeFactory::issueInvite() on ACCEPTANCE, which is the moment
    they become an employee.

    ── WHY THE ACCEPT LINK IS IN HERE ─────────────────────────────────────────

    It used to be minted separately, by a recruiter pressing a second button on
    another screen after the offer email had already gone out. So the candidate
    received an offer with no way to answer it, and waited for a follow-up that
    depended on somebody remembering. One email, one action.

    The link carries a 64-character token that opens this offer and nothing
    else. It expires, and it burns on use.
--}}
<div style="font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #1a1a1a; max-width: 600px;">

    <p style="font-size: 18px; font-weight: 600; margin: 0 0 16px;">
        Congratulations{{ $candidateName ? ', ' . $candidateName : '' }}!
    </p>

    <p style="margin: 0 0 16px;">
        We are delighted to offer you the position of
        <strong>{{ $offer->position }}</strong>@if(!empty($organisation)) at <strong>{{ $organisation }}</strong>@endif.
        Everyone who met you during the process was impressed, and we would be glad to have you join us.
    </p>

    <p style="margin: 0 0 8px;">Your formal offer letter is attached. In summary:</p>

    <table cellpadding="0" cellspacing="0" style="margin: 0 0 20px; border-collapse: collapse;">
        <tr>
            <td style="padding: 6px 24px 6px 0; color: #666;">Position</td>
            <td style="padding: 6px 0; font-weight: 600;">{{ $offer->position ?: '—' }}</td>
        </tr>
        @if(!empty($offer->salary))
        <tr>
            <td style="padding: 6px 24px 6px 0; color: #666;">Compensation</td>
            <td style="padding: 6px 0; font-weight: 600;">{{ $offer->salary }}</td>
        </tr>
        @endif
        @if(!empty($offer->start_date))
        <tr>
            <td style="padding: 6px 24px 6px 0; color: #666;">Start date</td>
            <td style="padding: 6px 0; font-weight: 600;">
                {{ \Carbon\Carbon::parse($offer->start_date)->format('j F Y') }}
            </td>
        </tr>
        @endif
    </table>

    @if(!empty($offer->notes))
        <p style="margin: 0 0 20px; padding: 12px 16px; background: #f6f6f6; border-radius: 6px;">
            {{ $offer->notes }}
        </p>
    @endif

    @if(!empty($responseUrl))
        <p style="margin: 0 0 12px;">
            You can accept or decline using the button below. It is personal to you.
        </p>

        <p style="margin: 0 0 20px;">
            <a href="{{ $responseUrl }}"
               style="display: inline-block; padding: 12px 24px; background: #1a1a1a; color: #ffffff;
                      text-decoration: none; border-radius: 6px; font-weight: 600;">
                Respond to this offer
            </a>
        </p>

        {{-- Some clients strip buttons, so the address is written out too. --}}
        <p style="margin: 0 0 20px; font-size: 13px; color: #666;">
            If the button does not work, copy this address into your browser:<br>
            <span style="word-break: break-all;">{{ $responseUrl }}</span>
        </p>

        @if(!empty($expiresAt))
            <p style="margin: 0 0 20px; font-size: 13px; color: #666;">
                This link is valid until {{ \Carbon\Carbon::parse($expiresAt)->format('j F Y') }}.
            </p>
        @endif
    @else
        <p style="margin: 0 0 20px;">
            Someone from the hiring team will be in touch shortly about next steps.
        </p>
    @endif

    <p style="margin: 0 0 4px;">We are looking forward to working with you.</p>
    <p style="margin: 0; color: #666;">
        {{ $organisation ?: 'The hiring team' }}
    </p>
</div>
