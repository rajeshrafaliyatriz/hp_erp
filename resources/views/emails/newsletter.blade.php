{{-- Convert newlines to <br> for HTML --}}
{!! nl2br(e($body)) !!}

@if($ctaLink && $ctaText)
<p>
    <a href="{{ $ctaLink }}" style="background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">{{ $ctaText }}</a>
</p>
@endif