{{--
    Omnichannel-Timeline eines Kunden (erwartet $timeline aus
    CustomerConversationService::timeline). Genutzt von der
    Kundenkommunikation und vom Kundenakte-Tab "Kommunikation".
    Voraussetzung auf der Seite: @include('partials.chat_styles').
--}}
@php $lastDay = null; @endphp
@forelse($timeline as $item)
    @if($item['day'] !== $lastDay)
        <div class="d24c-day">{{ $item['day'] }}</div>
        @php $lastDay = $item['day']; @endphp
    @endif
    @if($item['style'] === 'bubble')
    @php $m = $item['message']; @endphp
    <div class="d24c-bub {{ $item['own'] ? 'me' : 'them' }}" data-kind="{{ $item['kind'] }}" @if($m) data-mid="{{ $m->id }}" @endif>
        @if($item['tag'])<a class="kx-tag" href="{{ $item['url'] }}">{{ $item['tag'] }}</a>@endif
        @if($item['own'] && $item['sender'])<span class="d24c-sender">{{ $item['sender'] }}</span>@endif
        <span class="d24c-body">{{ $item['body'] }}</span>
        @if($m)
        @foreach($m->attachments as $att)
        <span class="d24c-att">
            <span class="d24c-att-n">{{ $att->isImage() ? '🖼️' : ($att->isPdf() ? '📄' : '📎') }} {{ $att->file_name }}</span>
            <span class="d24c-attbtns">
                @if($att->isViewable())<a href="{{ route('admin.messages.attachment.view', $att->id) }}" target="_blank" rel="noopener" data-preview-open data-preview-url="{{ route('admin.messages.attachment.view', $att->id) }}" data-preview-name="{{ $att->file_name }}" data-preview-kind="{{ $att->isImage() ? 'image' : 'pdf' }}" data-preview-download="{{ route('admin.messages.attachment', $att->id) }}">👁 {{ __('Anzeigen') }}</a>@endif
                <a href="{{ route('admin.messages.attachment', $att->id) }}">⬇ {{ __('Herunterladen') }}</a>
            </span>
        </span>
        @endforeach
        @endif
        <span class="d24c-tm">{{ $item['time'] }}@if($item['kind'] === 'chat' && $item['own'])<span class="d24c-ticks{{ $item['read'] ? ' read' : '' }}" title="{{ $item['read'] ? __('Gelesen') : __('Gesendet') }}">{{ $item['read'] ? '✓✓' : '✓' }}</span>@endif</span>
    </div>
    @else
    <div class="kx-card {{ $item['style'] === 'internal' ? 'kx-internal' : '' }}" data-kind="{{ $item['kind'] }}">
        <span class="kx-card-head">{{ $item['icon'] }} @if($item['tag'] && $item['style'] !== 'internal')<b>{{ $item['tag'] }}</b> · @endif{{ $item['title'] }} · {{ $item['time'] }}</span>
        @if($item['body'])<span class="kx-card-body">{{ $item['body'] }}</span>@endif
        @if($item['url'])<a class="kx-card-link" href="{{ $item['url'] }}">Öffnen →</a>@endif
    </div>
    @endif
@empty
    <div class="d24c-empty">👋 Noch keine Kommunikation. Starten Sie die Unterhaltung – der Kunde sieht Ihre Nachricht sofort im Portal.</div>
@endforelse
