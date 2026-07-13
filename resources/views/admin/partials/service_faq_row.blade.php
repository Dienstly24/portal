<div class="faq-row" style="border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:12px;">
    <div class="grid-2">
        <div class="field" style="margin-bottom:8px;"><label>Frage (DE)</label>
            <input type="text" name="faq_q_de[]" maxlength="255" value="{{ $q_de ?? '' }}"></div>
        <div class="field" style="margin-bottom:8px;"><label>Frage (AR)</label>
            <input type="text" name="faq_q_ar[]" maxlength="255" dir="rtl" value="{{ $q_ar ?? '' }}"></div>
    </div>
    <div class="grid-2">
        <div class="field" style="margin-bottom:8px;"><label>Antwort (DE)</label>
            <textarea name="faq_a_de[]" rows="2" maxlength="2000">{{ $a_de ?? '' }}</textarea></div>
        <div class="field" style="margin-bottom:8px;"><label>Antwort (AR)</label>
            <textarea name="faq_a_ar[]" rows="2" maxlength="2000" dir="rtl">{{ $a_ar ?? '' }}</textarea></div>
    </div>
    <button type="button" class="btn btn-ghost" style="padding:5px 12px;color:#A32D2D;" onclick="removeFaqRow(this)">Entfernen</button>
</div>
